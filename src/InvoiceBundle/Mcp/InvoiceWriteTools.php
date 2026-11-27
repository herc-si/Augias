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
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManager;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\InvoiceBundle\Cloner\InvoiceCloner;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Email\ManualInvoiceReminderEmail;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\InvoiceBundle\Repository\RecurringInvoiceRepository;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\EntityNormalizer;
use Augias\McpBundle\Mcp\Tool\InvoiceTaxBuilder;
use Augias\McpBundle\Mcp\Tool\LineItemBuilder;
use Augias\McpBundle\Mcp\Tool\PlanLimits;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use LogicException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class InvoiceWriteTools
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private RecurringInvoiceRepository $recurringInvoiceRepository,
        private ClientRepository $clientRepository,
        private InvoiceCloner $cloner,
        private InvoiceManager $invoiceManager,
        private LineItemBuilder $lineItemBuilder,
        private InvoiceTaxBuilder $invoiceTaxBuilder,
        private TotalCalculator $totalCalculator,
        private EntityManagerInterface $entityManager,
        private EntityNormalizer $normalizer,
        #[Autowire(service: 'state_machine.invoice')]
        private WorkflowInterface $invoiceWorkflow,
        #[Autowire(service: 'state_machine.recurring_invoice')]
        private WorkflowInterface $recurringInvoiceWorkflow,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private McpScopeGuard $scopeGuard,
        private PlanLimits $planLimits,
        private ElectronicInvoiceManagerInterface $electronicInvoiceManager,
    ) {
    }

    /**
     * Create a new invoice for an existing client.
     *
     * Totals (subtotal / tax / discount / total) are calculated server-side
     * from the line items. The invoice starts as a draft — apply
     * `apply_invoice_transition` to move it through the workflow.
     *
     * @param string                          $client_id      Client ULID (must belong to the active company)
     * @param list<array<string, mixed>>      $lines          Line items: [{description, price, qty, supply_type?: goods|services, tax_id?, taxes?: [{tax_id, sequence?, compound?}]}, ...].
     *                                                        Price is in the minor unit (e.g. cents). Provide `taxes[]` for multi-tax lines
     *                                                        (India GST split, Quebec GST+QST compound); `tax_id` is the legacy single-tax shorthand.
     * @param string|null                     $invoice_date   ISO-8601 date (defaults to today)
     * @param string|null                     $due            ISO-8601 due date (optional)
     * @param string|null                     $discount_type  "percentage" or "money" (optional)
     * @param int|float|null                  $discount_value Numeric discount value; required if discount_type is set
     * @param string|null                     $terms          Optional terms text
     * @param string|null                     $notes          Optional notes text
     * @param string|null                     $subject        Optional subject (what the document is about), printed above the lines
     * @param list<string>                    $contact_ids    Client contact ULIDs to attach to the invoice (optional)
     * @param string|null                     $invoice_id     Explicit invoice number; if omitted, the draft is numbered when finalised
     * @param list<array<string, mixed>>      $invoice_taxes  Invoice-level taxes (withholding/surcharge/informational):
     *                                                        [{tax_id, direction: Additive|Deductive|Informational, sequence?, note?}].
     *                                                        Deductive (TDS) reduces payable; Additive grows total; Informational records amount=0.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_invoice', description: 'Create a new invoice for a client, with line items, optional subject, discount, due date, contacts, and invoice-level taxes.')]
    #[McpScopeRequired(McpScope::Write)]
    public function createInvoice(
        string $client_id,
        array $lines,
        ?string $invoice_date = null,
        ?string $due = null,
        ?string $discount_type = null,
        int | float | null $discount_value = null,
        ?string $terms = null,
        ?string $notes = null,
        ?string $subject = null,
        array $contact_ids = [],
        ?string $invoice_id = null,
        array $invoice_taxes = [],
    ): array {
        $this->scopeGuard->require(McpScope::Write);

        $invoice = $this->newInvoice($client_id, $lines, $invoice_date, $due, $discount_type, $discount_value, $terms, $notes, $subject, $contact_ids, $invoice_taxes, true);

        // A number given explicitly is kept; otherwise the draft takes one
        // when it is finalised.
        $invoice->setInvoiceId($invoice_id ?? '');

        $invoice = $this->invoiceManager->create($invoice);

        return $this->normalizer->normalize($invoice);
    }

    /**
     * Why creating this invoice would fail, if it would, without creating
     * it: the assistant asks before showing the person a change to confirm.
     *
     * @param array<string, mixed> $arguments the tool's arguments, by name
     */
    public function creationProblem(array $arguments): ?string
    {
        try {
            $this->newInvoice(
                (string) ($arguments['client_id'] ?? ''),
                \is_array($arguments['lines'] ?? null) ? $arguments['lines'] : [],
                isset($arguments['invoice_date']) ? (string) $arguments['invoice_date'] : null,
                isset($arguments['due']) ? (string) $arguments['due'] : null,
                isset($arguments['discount_type']) ? (string) $arguments['discount_type'] : null,
                isset($arguments['discount_value']) && is_numeric($arguments['discount_value']) ? $arguments['discount_value'] + 0 : null,
                null,
                null,
                null,
                \is_array($arguments['contact_ids'] ?? null) ? $arguments['contact_ids'] : [],
                \is_array($arguments['invoice_taxes'] ?? null) ? $arguments['invoice_taxes'] : [],
                false,
            );
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * The invoice the arguments describe, not saved and without a number.
     *
     * @param list<array<string, mixed>> $lines
     * @param list<mixed>                $contact_ids
     * @param list<array<string, mixed>> $invoice_taxes
     * @param bool                       $linkContacts false for a check: a contact keeps the invoices it is on, and would save this one
     */
    private function newInvoice(
        string $client_id,
        array $lines,
        ?string $invoice_date,
        ?string $due,
        ?string $discount_type,
        int | float | null $discount_value,
        ?string $terms,
        ?string $notes,
        ?string $subject,
        array $contact_ids,
        array $invoice_taxes,
        bool $linkContacts,
    ): Invoice {
        $this->planLimits->assertCanCreateInvoice();

        $client = $this->clientRepository->find(UlidParser::parse($client_id, 'client_id'));

        if (! $client instanceof Client) {
            throw new ToolCallException(sprintf('Client %s not found.', $client_id));
        }

        $invoice = new Invoice();
        $invoice->setClient($client);
        $invoice->setInvoiceDate($this->parseDate($invoice_date, CarbonImmutable::now()));

        if ($due !== null) {
            $invoice->setDue($this->parseDate($due, CarbonImmutable::now()));
        }

        foreach ($this->lineItemBuilder->buildInvoiceLines($lines) as $line) {
            $invoice->addLine($line);
        }

        $invoice->setDiscount($this->lineItemBuilder->buildDiscount($discount_type, $discount_value));

        if ($terms !== null) {
            $invoice->setTerms($terms);
        }

        if ($notes !== null) {
            $invoice->setNotes($notes);
        }

        $invoice->setSubject($subject);

        foreach ($contact_ids as $index => $contactId) {
            if (! \is_string($contactId)) {
                throw new ToolCallException(sprintf('contact_ids[%d] must be a string.', $index));
            }

            $contact = $this->entityManager
                ->getRepository(Contact::class)
                ->find(UlidParser::parse($contactId, sprintf('contact_ids[%d]', $index)));

            if (! $contact instanceof Contact || $contact->getClient()?->getId()?->equals($client->getId()) !== true) {
                throw new ToolCallException(sprintf('Contact %s does not belong to this client.', $contactId));
            }

            if ($linkContacts) {
                $invoice->addUser($contact);
            }
        }

        $this->invoiceTaxBuilder->attach($invoice, $invoice_taxes);

        $this->totalCalculator->calculateTotals($invoice);

        return $invoice;
    }

    private function parseDate(?string $value, DateTimeImmutable $default): DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new ToolCallException(sprintf('Invalid date "%s". Use ISO-8601 format (YYYY-MM-DD).', $value));
        }
    }

    /**
     * Apply a workflow transition to an invoice.
     *
     * @param string $invoice_id Invoice ULID
     * @param string $transition One of: accept, cancel, overdue, pay, reopen, archive
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'apply_invoice_transition', description: 'Apply a workflow transition (accept, cancel, overdue, pay, reopen, archive) to an invoice.')]
    #[McpScopeRequired(McpScope::Write)]
    public function applyInvoiceTransition(string $invoice_id, string $transition): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $invoice = $this->invoiceRepository->find(UlidParser::parse($invoice_id, 'invoice_id'));

        if (! $invoice instanceof Invoice) {
            throw new ToolCallException(sprintf('Invoice %s not found.', $invoice_id));
        }

        if (! $this->invoiceWorkflow->can($invoice, $transition)) {
            throw new ToolCallException(sprintf(
                'Transition "%s" is not enabled for invoice in status "%s".',
                $transition,
                $invoice->getStatus()?->value ?? 'unknown',
            ));
        }

        $this->invoiceWorkflow->apply($invoice, $transition);
        $this->entityManager->flush();

        return $this->normalizer->normalize($invoice);
    }

    /**
     * Apply a workflow transition to a recurring invoice.
     *
     * @param string $recurring_id Recurring invoice ULID
     * @param string $transition   One of: activate, pause, resume, complete, cancel, archive
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'apply_recurring_transition', description: 'Apply a workflow transition to a recurring invoice.')]
    #[McpScopeRequired(McpScope::Write)]
    public function applyRecurringTransition(string $recurring_id, string $transition): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $recurring = $this->recurringInvoiceRepository->find(UlidParser::parse($recurring_id, 'recurring_id'));

        if (! $recurring instanceof RecurringInvoice) {
            throw new ToolCallException(sprintf('Recurring invoice %s not found.', $recurring_id));
        }

        if (! $this->recurringInvoiceWorkflow->can($recurring, $transition)) {
            throw new ToolCallException(sprintf(
                'Transition "%s" is not enabled for recurring invoice in status "%s".',
                $transition,
                $recurring->getStatus()?->value ?? 'unknown',
            ));
        }

        $this->recurringInvoiceWorkflow->apply($recurring, $transition);
        $this->entityManager->flush();

        return $this->normalizer->normalize($recurring);
    }

    /**
     * Clone an existing invoice into a new draft.
     *
     * @param string $invoice_id Invoice ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'clone_invoice', description: 'Clone an existing invoice into a new draft with the same line items.')]
    #[McpScopeRequired(McpScope::Write)]
    public function cloneInvoice(string $invoice_id): array
    {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertCanCreateInvoice();

        $invoice = $this->invoiceRepository->find(UlidParser::parse($invoice_id, 'invoice_id'));

        if (! $invoice instanceof Invoice) {
            throw new ToolCallException(sprintf('Invoice %s not found.', $invoice_id));
        }

        $cloned = $this->cloner->clone($invoice);

        // InvoiceCloner::clone() only persists+flushes Invoice clones
        // (via invoiceManager->create()); RecurringInvoice clones come back
        // transient. Persist here so the MCP caller gets a saved entity and
        // the normalised payload exposes a real id rather than null.
        if ($cloned instanceof RecurringInvoice) {
            $this->entityManager->persist($cloned);
            $this->entityManager->flush();
        }

        return $this->normalizer->normalize($cloned);
    }

    /**
     * Send a manual reminder email to the contacts on an invoice.
     *
     * @param string $invoice_id Invoice ULID
     *
     * @return array{sent: bool, invoice_id: string}
     */
    #[McpTool(name: 'send_invoice_reminder', description: 'Email a manual reminder for an invoice to its contacts.')]
    #[McpScopeRequired(McpScope::Write)]
    public function sendInvoiceReminder(string $invoice_id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $invoice = $this->invoiceRepository->find(UlidParser::parse($invoice_id, 'invoice_id'));

        if (! $invoice instanceof Invoice) {
            throw new ToolCallException(sprintf('Invoice %s not found.', $invoice_id));
        }

        $problem = $this->recipientsProblem($invoice);
        if ($problem !== null) {
            throw new ToolCallException($problem);
        }

        try {
            $this->mailer->send(new ManualInvoiceReminderEmail($invoice));

            $this->logger->info('Manual reminder sent via MCP', [
                'invoice_id' => $invoice->getInvoiceId(),
                'company_id' => $invoice->getCompany()->getId()->toRfc4122(),
            ]);
        } catch (TransportExceptionInterface $exception) {
            // Don't surface transport-level detail (SMTP host, auth failures,
            // relay internals) to the AI agent — log it and keep the user
            // message generic.
            $this->logger->error('Failed to send manual MCP reminder', [
                'invoice_id' => $invoice->getInvoiceId(),
                'exception' => $exception,
            ]);

            throw new ToolCallException('Failed to send reminder. See server logs for details.', $exception->getCode(), $exception);
        }

        return [
            'sent' => true,
            'invoice_id' => $invoice->getId()?->toRfc4122() ?? $invoice_id,
        ];
    }

    /**
     * Why this invoice could not be sent (or reminded), if it could not:
     * asked before the person confirms (Rémi, 27/11/2026).
     */
    public function sendingProblem(string $invoice_id): ?string
    {
        // An unknown invoice is said by the call itself, as before.
        try {
            $invoice = $this->invoiceRepository->find(UlidParser::parse($invoice_id, 'invoice_id'));
        } catch (ToolCallException) {
            return null;
        }

        return $invoice instanceof Invoice ? $this->recipientsProblem($invoice) : null;
    }

    /**
     * An invoice without a contact goes to its client's contacts that have
     * an e-mail address. Null when it has someone to go to.
     */
    private function recipientsProblem(Invoice $invoice): ?string
    {
        if ($invoice->getUsers()->isEmpty()) {
            foreach ($invoice->getClient()?->getContacts() ?? [] as $contact) {
                if (trim((string) $contact->getEmail()) !== '') {
                    $invoice->addUser($contact);
                }
            }
        }

        return $invoice->getUsers()->isEmpty()
            ? 'Neither the invoice nor its client has a contact with an e-mail address to send it to: add one to the client with add_contact first.'
            : null;
    }

    /**
     * Send an invoice as the Send button does: a draft is finalised (numbered,
     * pending payment), emailed with its PDF to its contacts, and handed to the
     * electronic invoicing platform when the client is eligible.
     *
     * @param string $invoice_id Invoice ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'send_invoice', description: 'Send an invoice: finalise a draft (numbered, pending), email it with its PDF to its contacts, and submit it as an electronic invoice when the client is eligible.')]
    #[McpScopeRequired(McpScope::Write)]
    public function sendInvoice(string $invoice_id): array
    {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertCanSend();

        $invoice = $this->invoiceRepository->find(UlidParser::parse($invoice_id, 'invoice_id'));

        if (! $invoice instanceof Invoice) {
            throw new ToolCallException(sprintf('Invoice %s not found.', $invoice_id));
        }

        $problem = $this->recipientsProblem($invoice);
        if ($problem !== null) {
            throw new ToolCallException($problem);
        }

        if (InvoiceStatus::Pending !== $invoice->getStatus() && $this->invoiceWorkflow->can($invoice, Graph::TRANSITION_ACCEPT)) {
            $this->invoiceWorkflow->apply($invoice, Graph::TRANSITION_ACCEPT);
        }

        $this->entityManager->flush();

        $emailSent = true;

        try {
            $this->mailer->send(new InvoiceEmail($invoice));
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to send invoice email via MCP', ['invoice_id' => $invoice->getInvoiceId(), 'exception' => $exception]);
            $emailSent = false;
        }

        // The email and the electronic invoice are two deliveries of the same
        // invoice: a mail server down must not keep it off the platform.
        $electronic = null;

        if ($this->electronicInvoiceManager->isEligible($invoice)) {
            try {
                $submission = $this->electronicInvoiceManager->send($invoice);
                $electronic = $submission->isSuccess()
                    ? 'submitted'
                    : ($submission->getMessage() === ElectronicInvoiceManager::ONLY_DISBURSEMENTS ? 'not_needed_disbursements_only' : 'failed');
            } catch (LogicException $exception) {
                $this->logger->error('Failed to send electronic invoice via MCP', ['invoice_id' => $invoice->getInvoiceId(), 'exception' => $exception]);
                $electronic = 'failed';
            }
        }

        return [
            'email_sent' => $emailSent,
            // null when the client is not concerned by electronic invoicing.
            'electronic_invoice' => $electronic,
            'invoice' => $this->normalizer->normalize($invoice),
        ];
    }
}
