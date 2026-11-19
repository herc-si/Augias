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

namespace Augias\McpBundle\Mcp\Tool;

use Augias\BillBundle\Entity\Bill;
use Augias\CatalogBundle\Entity\Product;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoicingSync;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\InvoiceBundle\Service\CreditNoteApplier;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Security\McpScope;
use Augias\QuoteBundle\Entity\Quote;
use Augias\SaasBundle\Feature\Feature;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the screens offer around the documents: links to them, a search
 * across everything, the client's credit set against an invoice, a recurring
 * invoice run now, the electronic invoicing platform handed a document again
 * or asked for news.
 */
final readonly class MiscellaneousTools
{
    /**
     * @var array<string, array{class: class-string, fields: list<string>, label: string}>
     */
    private const array SEARCHABLE = [
        'client' => ['class' => Client::class, 'fields' => ['e.name', 'e.website'], 'label' => 'name'],
        'contact' => ['class' => Contact::class, 'fields' => ['e.firstName', 'e.lastName', 'e.email'], 'label' => 'email'],
        'invoice' => ['class' => Invoice::class, 'fields' => ['e.invoiceId', 'e.subject'], 'label' => 'invoiceId'],
        'quote' => ['class' => Quote::class, 'fields' => ['e.quoteId', 'e.subject'], 'label' => 'quoteId'],
        'credit_note' => ['class' => CreditNote::class, 'fields' => ['e.creditNoteId', 'e.subject'], 'label' => 'creditNoteId'],
        'bill' => ['class' => Bill::class, 'fields' => ['e.billNumber', 'e.notes'], 'label' => 'billNumber'],
        'product' => ['class' => Product::class, 'fields' => ['e.name', 'e.reference', 'e.description'], 'label' => 'name'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urls,
        private CreditNoteApplier $creditNoteApplier,
        private InvoiceManager $invoiceManager,
        private ElectronicInvoiceManagerInterface $electronicInvoiceManager,
        private ElectronicInvoicingSync $sync,
        private CompanySelector $companySelector,
        private EntityNormalizer $normalizer,
        private PlanLimits $planLimits,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * Search the clients, contacts, invoices, quotes, credit notes, purchases
     * and catalogue by name, number, subject or e-mail.
     *
     * @param string $query Text to look for (case-insensitive)
     * @param int    $limit Max hits per kind (1..25)
     *
     * @return array<string, list<array{id: string|null, label: string}>>
     */
    #[McpTool(name: 'search', description: 'Search across clients, contacts, invoices, quotes, credit notes, purchases and catalogue by name, number, subject or e-mail; returns ids to pass to get_resource.')]
    #[McpScopeRequired(McpScope::Read)]
    public function search(string $query, int $limit = 5): array
    {
        $this->scopeGuard->require(McpScope::Read);

        $text = trim($query);

        if ($text === '') {
            throw new ToolCallException('query cannot be empty.');
        }

        $pattern = '%' . strtr(mb_strtolower($text), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $limit = max(1, min($limit, 25));
        $results = [];

        foreach (self::SEARCHABLE as $kind => $definition) {
            $qb = $this->entityManager->createQueryBuilder()->select('e')->from($definition['class'], 'e');
            $qb->where($qb->expr()->orX(...array_map(
                static fn (string $field): string => sprintf("LOWER(%s) LIKE :pattern ESCAPE '!'", $field),
                $definition['fields'],
            )))
                ->setParameter('pattern', $pattern)
                ->orderBy('e.id', 'DESC')
                ->setMaxResults($limit);

            $hits = [];

            foreach ($qb->getQuery()->getResult() as $entity) {
                $hits[] = ['id' => $entity->getId()?->toRfc4122(), 'label' => $this->label($entity, $definition['label'])];
            }

            if ($hits !== []) {
                $results[$kind] = $hits;
            }
        }

        return $results;
    }

    /**
     * Where to see a document: its page in Augias, and for an invoice or a
     * quote, the page and PDF the client is sent (no sign-in needed).
     *
     * @param string $kind invoice, quote or credit_note
     * @param string $id   Document ULID
     *
     * @return array<string, string|null>
     */
    #[McpTool(name: 'get_document_links', description: 'Links to a document (kind: invoice, quote, credit_note): its page in Augias, and for an invoice or a quote the client\'s page and PDF, which open without signing in.')]
    #[McpScopeRequired(McpScope::Read)]
    public function getDocumentLinks(string $kind, string $id): array
    {
        $this->scopeGuard->require(McpScope::Read);

        $absolute = UrlGeneratorInterface::ABSOLUTE_URL;

        return match ($kind) {
            'invoice' => (function () use ($id, $absolute): array {
                $invoice = $this->find(Invoice::class, $id, 'Invoice');

                return [
                    'app_url' => $this->urls->generate('_invoices_view', ['id' => $invoice->getId(), '_format' => 'html'], $absolute),
                    'client_url' => $this->urls->generate('_view_invoice_external', ['uuid' => $invoice->getUuid()->toRfc4122()], $absolute),
                    'pdf_url' => $this->urls->generate('_view_invoice_external', ['uuid' => $invoice->getUuid()->toRfc4122(), '_format' => 'pdf'], $absolute),
                ];
            })(),
            'quote' => (function () use ($id, $absolute): array {
                $quote = $this->find(Quote::class, $id, 'Quote');

                return [
                    'app_url' => $this->urls->generate('_quotes_view', ['id' => $quote->getId(), '_format' => 'html'], $absolute),
                    'client_url' => $this->urls->generate('_view_quote_external', ['uuid' => $quote->getUuid()->toRfc4122()], $absolute),
                    'pdf_url' => $this->urls->generate('_view_quote_external', ['uuid' => $quote->getUuid()->toRfc4122(), '_format' => 'pdf'], $absolute),
                ];
            })(),
            'credit_note' => (function () use ($id, $absolute): array {
                $creditNote = $this->find(CreditNote::class, $id, 'Credit note');

                return [
                    'app_url' => $this->urls->generate('_credit_notes_view', ['id' => $creditNote->getId(), '_format' => 'html'], $absolute),
                    'pdf_url' => $this->urls->generate('_credit_notes_view', ['id' => $creditNote->getId(), '_format' => 'pdf'], $absolute),
                ];
            })(),
            default => throw new ToolCallException('kind must be invoice, quote or credit_note.'),
        };
    }

    /**
     * Set what the client is owed from their credit notes against an unpaid
     * invoice, as the "Use the credit" button does.
     *
     * @param string $invoice_id Invoice ULID (pending or overdue)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'apply_client_credit', description: 'Set the client\'s available credit (from issued credit notes) against an unpaid invoice.')]
    #[McpScopeRequired(McpScope::Write)]
    public function applyClientCredit(string $invoice_id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $invoice = $this->find(Invoice::class, $invoice_id, 'Invoice');

        if (! \in_array($invoice->getStatus(), [InvoiceStatus::Pending, InvoiceStatus::Overdue], true)) {
            throw new ToolCallException('Only an invoice still owed (pending or overdue) can take the client\'s credit.');
        }

        try {
            $applied = $this->creditNoteApplier->applyTo($invoice);
        } catch (AllocationException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }

        if ($applied->isZero()) {
            throw new ToolCallException('The client has no credit available to set against this invoice.');
        }

        return ['applied' => (string) $applied->toScale(0), 'invoice' => $this->normalizer->normalize($invoice)];
    }

    /**
     * Make today's invoice of a recurring invoice now, without waiting for
     * its schedule.
     *
     * @param string $recurring_id Recurring invoice ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'generate_recurring_invoice_now', description: 'Make an invoice from a recurring invoice now, without waiting for its schedule (once a day at most).')]
    #[McpScopeRequired(McpScope::Write)]
    public function generateRecurringInvoiceNow(string $recurring_id): array
    {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertFeature(Feature::RecurringInvoices, 'Recurring invoices');
        $this->planLimits->assertCanCreateInvoice();

        $recurring = $this->find(RecurringInvoice::class, $recurring_id, 'Recurring invoice');

        if ($recurring->hasInvoiceForDay(CarbonImmutable::now())) {
            throw new ToolCallException('An invoice has already been made from this recurring invoice today.');
        }

        $invoice = $this->invoiceManager->create($this->invoiceManager->createFromRecurring($recurring));

        return $this->normalizer->normalize($invoice);
    }

    /**
     * Hand an issued invoice or credit note to the electronic invoicing
     * platform again, after a refusal or a failure.
     *
     * @param string $kind invoice or credit_note
     * @param string $id   Document ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'send_electronic_document', description: 'Submit an issued invoice or credit note (kind: invoice, credit_note) to the electronic invoicing platform again, e.g. after a failure.')]
    #[McpScopeRequired(McpScope::Write)]
    public function sendElectronicDocument(string $kind, string $id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $document = match ($kind) {
            'invoice' => $this->find(Invoice::class, $id, 'Invoice'),
            'credit_note' => $this->find(CreditNote::class, $id, 'Credit note'),
            default => throw new ToolCallException('kind must be invoice or credit_note.'),
        };

        if (! $this->electronicInvoiceManager->isEligible($document)) {
            throw new ToolCallException('This document is not concerned by electronic invoicing (setting off, no platform connected, a draft, or a client without SIRET or SIREN).');
        }

        try {
            $submission = $this->electronicInvoiceManager->send($document);
        } catch (LogicException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }

        return [
            'success' => $submission->isSuccess(),
            'status_code' => $submission->getStatusCode(),
            'message' => $submission->getMessage(),
        ];
    }

    /**
     * Ask the electronic invoicing platform for news: invoices received,
     * statuses of the ones sent, e-reporting pending.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'sync_electronic_invoicing', description: 'Ask the electronic invoicing platform for news now: invoices received, statuses of those sent, e-reporting pending (at most every few minutes).')]
    #[McpScopeRequired(McpScope::Write)]
    public function syncElectronicInvoicing(): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $companyId = $this->companySelector->getCompany();
        $company = $companyId === null ? null : $this->entityManager->getRepository(Company::class)->find($companyId);

        if (! $company instanceof Company) {
            throw new ToolCallException('No active company.');
        }

        $result = $this->sync->syncNow($company);

        if ($result === null) {
            throw new ToolCallException('The platform was asked a moment ago; try again in a few minutes.');
        }

        return $result;
    }

    private function label(object $entity, string $property): string
    {
        $getter = 'get' . ucfirst($property);
        $value = method_exists($entity, $getter) ? $entity->{$getter}() : null;
        $client = method_exists($entity, 'getClient') ? $entity->getClient() : (method_exists($entity, 'getSupplier') ? $entity->getSupplier() : null);

        $label = \is_string($value) && $value !== '' ? $value : '(no number yet)';

        if ($entity instanceof Contact) {
            $label = trim(sprintf('%s %s <%s>', $entity->getFirstName(), $entity->getLastName(), $entity->getEmail()));
        }

        if ($client instanceof Client && ! $entity instanceof Contact) {
            $label .= ' — ' . $client->getName();
        }

        return $label;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, string $id, string $label): object
    {
        $entity = $this->entityManager->getRepository($class)->find(UlidParser::parse($id, 'id'));

        if (! $entity instanceof $class) {
            throw new ToolCallException(sprintf('%s %s not found.', $label, $id));
        }

        return $entity;
    }
}
