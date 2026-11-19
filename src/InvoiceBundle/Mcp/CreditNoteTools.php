<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\InvoiceBundle\Mcp;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\InvoiceBundle\DTO\CreditNoteFormDTO;
use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Manager\CreditNoteFormManager;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Repository\CreditNoteRepository;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\EntityNormalizer;
use Augias\McpBundle\Mcp\Tool\LineItemBuilder;
use Augias\McpBundle\Mcp\Tool\PlanLimits;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Credit notes (avoirs): raising one, issuing and sending it, and then using
 * what it owes the client, set against an invoice or refunded.
 */
final readonly class CreditNoteTools
{
    public function __construct(
        private CreditNoteRepository $creditNoteRepository,
        private InvoiceRepository $invoiceRepository,
        private ClientRepository $clientRepository,
        private CreditNoteFormManager $formManager,
        private CreditNoteAllocator $allocator,
        private LineItemBuilder $lineItemBuilder,
        private TotalCalculator $totalCalculator,
        private EntityManagerInterface $entityManager,
        private EntityNormalizer $normalizer,
        private ValidatorInterface $validator,
        #[Autowire(service: 'state_machine.credit_note')]
        private WorkflowInterface $creditNoteWorkflow,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private McpScopeGuard $scopeGuard,
        private PlanLimits $planLimits,
    ) {
    }

    /**
     * Raise a credit note as a draft. Give an invoice_id alone to cancel that
     * invoice in full (every line, with its taxes); give lines to credit only
     * part of it, or to credit a client without an invoice.
     *
     * @param string                     $reason           One of: cancellation, return, rebate, commercial_gesture
     * @param string|null                $invoice_id       ULID of the invoice being credited (its client is used)
     * @param string|null                $client_id        Client ULID, when no invoice is credited
     * @param list<array<string, mixed>> $lines            Lines, as on create_invoice (price in the minor unit, positive); omit with invoice_id to cancel it in full
     * @param list<string>               $contact_ids      Contacts the credit note is sent to; defaults to the invoice's contacts
     * @param string|null                $credit_note_date ISO-8601 date (defaults to today)
     * @param string|null                $subject          What the credit note is about
     * @param string|null                $notes            Notes printed on the credit note
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_credit_note', description: 'Raise a draft credit note (avoir): give invoice_id alone to cancel an invoice in full, or lines to credit part of it or a client. Issue it with issue_credit_note or send_credit_note.')]
    #[McpScopeRequired(McpScope::Write)]
    public function createCreditNote(
        string $reason,
        ?string $invoice_id = null,
        ?string $client_id = null,
        array $lines = [],
        array $contact_ids = [],
        ?string $credit_note_date = null,
        ?string $subject = null,
        ?string $notes = null,
    ): array {
        $this->scopeGuard->require(McpScope::Write);

        $creditReason = CreditReason::tryFrom($reason) ?? throw new ToolCallException(sprintf(
            'Invalid reason "%s". Valid values: %s.',
            $reason,
            implode(', ', array_map(static fn (CreditReason $case): string => $case->value, CreditReason::cases())),
        ));

        $invoice = null;

        if ($invoice_id !== null) {
            $invoice = $this->invoiceRepository->find(UlidParser::parse($invoice_id, 'invoice_id'));

            if (! $invoice instanceof Invoice) {
                throw new ToolCallException(sprintf('Invoice %s not found.', $invoice_id));
            }

            if (! $invoice->isNumbered()) {
                throw new ToolCallException('A draft invoice is not credited: change or delete it instead.');
            }
        }

        if ($invoice instanceof Invoice && $lines === []) {
            $dto = $this->formManager->cancellationOf($invoice);
        } else {
            $client = $invoice?->getClient();

            if (! $client instanceof Client) {
                if ($client_id === null) {
                    throw new ToolCallException('Give the invoice_id being credited, or a client_id.');
                }

                $client = $this->clientRepository->find(UlidParser::parse($client_id, 'client_id'));

                if (! $client instanceof Client) {
                    throw new ToolCallException(sprintf('Client %s not found.', $client_id));
                }
            }

            $dto = $this->formManager->blank();
            $dto->client = $client;
            $dto->creditedInvoice = $invoice;
            $dto->subject = $invoice?->getSubject();

            foreach ($this->lineItemBuilder->buildCreditNoteLines($lines) as $line) {
                $dto->lines->add($line->updateTotal());
            }

            foreach ($invoice?->getUsers() ?? [] as $contact) {
                $dto->users->add($contact);
            }
        }

        $dto->reason = $creditReason;

        if ($credit_note_date !== null) {
            $dto->creditNoteDate = $this->parseDate($credit_note_date);
        }

        if ($subject !== null) {
            $dto->subject = $subject;
        }

        $dto->notes = $notes ?? $dto->notes;

        if ($contact_ids !== []) {
            $dto->users->clear();
            $this->addContacts($dto, $contact_ids);
        }

        $this->validate($dto);

        $creditNote = $this->formManager->createFromDTO($dto);
        $this->totalCalculator->calculateTotals($creditNote);

        if ($invoice instanceof Invoice) {
            $this->assertWithinWhatWasInvoiced($invoice, $creditNote);
        }

        $this->entityManager->persist($creditNote);
        $this->entityManager->flush();

        return $this->normalizer->normalize($creditNote);
    }

    /**
     * Issue a draft credit note: it takes its number and can no longer be
     * changed. It is submitted to the electronic invoicing platform when the
     * client is eligible. Nothing is emailed; see send_credit_note.
     *
     * @param string $credit_note_id Credit note ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'issue_credit_note', description: 'Issue a draft credit note: it is numbered and frozen (and submitted electronically if the client is eligible), without emailing it.')]
    #[McpScopeRequired(McpScope::Write)]
    public function issueCreditNote(string $credit_note_id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $creditNote = $this->find($credit_note_id);

        if (! $this->creditNoteWorkflow->can($creditNote, CreditNoteGraph::TRANSITION_ISSUE)) {
            throw new ToolCallException(sprintf('A credit note in status "%s" cannot be issued.', $creditNote->getStatus()->value));
        }

        $this->issue($creditNote);

        return $this->normalizer->normalize($creditNote);
    }

    /**
     * Email a credit note with its PDF to its contacts, issuing it first if it
     * is still a draft.
     *
     * @param string $credit_note_id Credit note ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'send_credit_note', description: 'Email a credit note with its PDF to its contacts, issuing it first if it is a draft.')]
    #[McpScopeRequired(McpScope::Write)]
    public function sendCreditNote(string $credit_note_id): array
    {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertCanSend();

        $creditNote = $this->find($credit_note_id);

        if ($creditNote->getUsers()->isEmpty()) {
            throw new ToolCallException('The credit note has no contact to send it to.');
        }

        if ($this->creditNoteWorkflow->can($creditNote, CreditNoteGraph::TRANSITION_ISSUE)) {
            $this->issue($creditNote);
        }

        $emailSent = true;

        try {
            $this->mailer->send(new CreditNoteEmail($creditNote));
        } catch (TransportExceptionInterface $exception) {
            // Issued either way: only the delivery failed.
            $this->logger->error('Could not send credit note via MCP', ['id' => $creditNote->getCreditNoteId(), 'exception' => $exception]);
            $emailSent = false;
        }

        return [
            'email_sent' => $emailSent,
            'credit_note' => $this->normalizer->normalize($creditNote),
        ];
    }

    /**
     * Use what an issued credit note owes the client: set it against an
     * invoice they still owe (offset), or record that it was paid back (refund).
     *
     * @param string      $credit_note_id Credit note ULID (must be issued)
     * @param string      $kind           "offset" (set against an invoice) or "refund" (paid back)
     * @param int|string  $amount         Amount in the minor unit (e.g. cents), at most what the credit note still owes
     * @param string|null $invoice_id     Invoice ULID, required for an offset
     * @param string|null $allocated_on   ISO-8601 date (defaults to today)
     * @param string|null $notes          Optional note (e.g. transfer reference)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'allocate_credit_note', description: 'Use an issued credit note: kind "offset" sets an amount against an unpaid invoice of the same client, kind "refund" records it was paid back. Amount in the minor unit.')]
    #[McpScopeRequired(McpScope::Write)]
    public function allocateCreditNote(
        string $credit_note_id,
        string $kind,
        int | string $amount,
        ?string $invoice_id = null,
        ?string $allocated_on = null,
        ?string $notes = null,
    ): array {
        $this->scopeGuard->require(McpScope::Write);

        $creditNote = $this->find($credit_note_id);

        $allocationKind = AllocationKind::tryFrom($kind) ?? throw new ToolCallException('kind must be "offset" or "refund".');

        $invoice = null;

        if ($invoice_id !== null) {
            $invoice = $this->invoiceRepository->find(UlidParser::parse($invoice_id, 'invoice_id'));

            if (! $invoice instanceof Invoice) {
                throw new ToolCallException(sprintf('Invoice %s not found.', $invoice_id));
            }
        }

        try {
            $value = BigDecimal::of((string) $amount);
        } catch (MathException) {
            throw new ToolCallException(sprintf('Invalid amount "%s": a whole number in the minor unit (e.g. cents).', (string) $amount));
        }

        try {
            $this->allocator->allocate(
                $creditNote,
                $allocationKind,
                $value->toBigInteger(),
                $invoice,
                $allocated_on !== null ? $this->parseDate($allocated_on) : CarbonImmutable::now(),
                $notes,
            );
        } catch (AllocationException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        } catch (MathException) {
            throw new ToolCallException('The amount must be a whole number in the minor unit (e.g. cents).');
        }

        return $this->normalizer->normalize($creditNote);
    }

    private function issue(CreditNote $creditNote): void
    {
        $this->creditNoteWorkflow->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);
        $creditNote->setIssuedAt(CarbonImmutable::now());
        $this->entityManager->flush();
    }

    /**
     * The form shows what was invoiced and already credited; a caller that
     * cannot see it is held to it: a credit note never gives back more than
     * the invoice charged.
     */
    private function assertWithinWhatWasInvoiced(Invoice $invoice, CreditNote $creditNote): void
    {
        $left = $invoice->getTotal()->toBigDecimal()->minus($this->creditNoteRepository->issuedTotalFor($invoice));

        if ($creditNote->getTotal()->toBigDecimal()->isGreaterThan($left)) {
            throw new ToolCallException(sprintf(
                'This credit note (%s) is more than what is left to credit on invoice %s (%s, in the minor unit).',
                (string) $creditNote->getTotal(),
                $invoice->getInvoiceId(),
                (string) $left,
            ));
        }
    }

    /**
     * @param list<mixed> $contactIds
     */
    private function addContacts(CreditNoteFormDTO $dto, array $contactIds): void
    {
        foreach ($contactIds as $index => $contactId) {
            if (! \is_string($contactId)) {
                throw new ToolCallException(sprintf('contact_ids[%d] must be a string.', $index));
            }

            $contact = $this->entityManager
                ->getRepository(Contact::class)
                ->find(UlidParser::parse($contactId, sprintf('contact_ids[%d]', $index)));

            if (! $contact instanceof Contact || $contact->getClient()?->getId()?->equals($dto->client?->getId()) !== true) {
                throw new ToolCallException(sprintf('Contact %s does not belong to this client.', $contactId));
            }

            $dto->users->add($contact);
        }
    }

    private function validate(CreditNoteFormDTO $dto): void
    {
        $errors = $this->validator->validate($dto);

        if (\count($errors) === 0) {
            return;
        }

        $messages = [];

        foreach ($errors as $error) {
            $path = $error->getPropertyPath() === 'users' ? 'contact_ids' : $error->getPropertyPath();
            $messages[] = sprintf('%s: %s', $path, $error->getMessage());
        }

        throw new ToolCallException(sprintf('Validation failed: %s', implode('; ', $messages)));
    }

    private function find(string $creditNoteId): CreditNote
    {
        $creditNote = $this->creditNoteRepository->find(UlidParser::parse($creditNoteId, 'credit_note_id'));

        if (! $creditNote instanceof CreditNote) {
            throw new ToolCallException(sprintf('Credit note %s not found.', $creditNoteId));
        }

        return $creditNote;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new ToolCallException(sprintf('Invalid date "%s". Use ISO-8601 format (YYYY-MM-DD).', $value));
        }
    }
}
