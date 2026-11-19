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
use Augias\BillBundle\Enum\BillStatus;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\CoreBundle\Exception\DocumentMustBeKept;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Security\McpScope;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Changing or deleting a document already written. Only what has not been
 * handed over can be changed or deleted: a draft invoice or credit note, a
 * quote not yet answered, a purchase not paid. An invoice issued is corrected
 * by a credit note, as the law asks.
 */
final readonly class DocumentEditTools
{
    public function __construct(
        private DocumentEditor $editor,
        private EntityManagerInterface $entityManager,
        private EntityNormalizer $normalizer,
        private CategoryRepository $categoryRepository,
        private SystemConfig $systemConfig,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * Change a draft invoice. Only the keys given are changed; "lines" replaces
     * every line (same shape as create_invoice). An issued invoice cannot be
     * changed: raise a credit note with create_credit_note.
     *
     * @param string               $invoice_id Invoice ULID
     * @param array<string, mixed> $changes    Any of: invoice_date, due, delivery_date (ISO-8601 or null), subject, terms, notes,
     *                                         discount_type, discount_value, lines, contact_ids, invoice_taxes, invoice_number
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_invoice', description: 'Change a draft invoice: changes is an object with any of invoice_date, due, delivery_date, subject, terms, notes, discount_type, discount_value, lines (replaces all lines), contact_ids, invoice_taxes, invoice_number. An issued invoice is corrected with create_credit_note instead.')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateInvoice(string $invoice_id, #[Schema(type: 'object', additionalProperties: true)] array $changes): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $invoice = $this->find(Invoice::class, $invoice_id, 'Invoice');

        if (! \in_array($invoice->getStatus(), [InvoiceStatus::New, InvoiceStatus::Draft], true)) {
            throw new ToolCallException(sprintf('Invoice %s has been issued (status "%s") and cannot be changed: raise a credit note with create_credit_note, then a new invoice.', $invoice->getInvoiceId(), $invoice->getStatus()->value));
        }

        $own = ['invoice_date', 'due', 'delivery_date', 'invoice_number'];
        $this->editor->apply($invoice, $changes, $own);

        if (\array_key_exists('invoice_date', $changes)) {
            $invoice->setInvoiceDate($this->editor->date($changes['invoice_date'], 'invoice_date') ?? throw new ToolCallException('invoice_date cannot be empty.'));
        }

        if (\array_key_exists('due', $changes)) {
            $invoice->setDue($this->editor->date($changes['due'], 'due'));
        }

        if (\array_key_exists('delivery_date', $changes)) {
            $invoice->setDeliveryDate($this->editor->date($changes['delivery_date'], 'delivery_date'));
        }

        if (\array_key_exists('invoice_number', $changes)) {
            $invoice->setInvoiceId(\is_string($changes['invoice_number']) ? $changes['invoice_number'] : '');
        }

        $this->editor->recalculate($invoice);

        return $this->normalizer->normalize($invoice);
    }

    /**
     * Change a quote not answered yet (draft or sent). Only the keys given are
     * changed; "lines" replaces every line.
     *
     * @param string               $quote_id Quote ULID
     * @param array<string, mixed> $changes  Any of: due (ISO-8601 or null), subject, terms, notes, discount_type, discount_value,
     *                                       lines, contact_ids, invoice_taxes
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_quote', description: 'Change a quote that is a draft or sent but not answered: changes is an object with any of due, subject, terms, notes, discount_type, discount_value, lines (replaces all lines), contact_ids, invoice_taxes.')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateQuote(string $quote_id, #[Schema(type: 'object', additionalProperties: true)] array $changes): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $quote = $this->find(Quote::class, $quote_id, 'Quote');

        if (! \in_array($quote->getStatus(), [QuoteStatus::New, QuoteStatus::Draft, QuoteStatus::Pending], true)) {
            throw new ToolCallException(sprintf('Quote %s is "%s" and cannot be changed any more: clone it with clone_quote instead.', $quote->getQuoteId(), $quote->getStatus()->value));
        }

        $this->editor->apply($quote, $changes, ['due']);

        if (\array_key_exists('due', $changes)) {
            $quote->setDue($this->editor->date($changes['due'], 'due'));
        }

        $this->editor->recalculate($quote);

        return $this->normalizer->normalize($quote);
    }

    /**
     * Change a draft credit note. Only the keys given are changed; "lines"
     * replaces every line.
     *
     * @param string               $credit_note_id Credit note ULID
     * @param array<string, mixed> $changes        Any of: credit_note_date (ISO-8601), reason, subject, terms, notes, lines, contact_ids
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_credit_note', description: 'Change a draft credit note: changes is an object with any of credit_note_date, reason, subject, terms, notes, lines (replaces all lines), contact_ids. An issued credit note cannot be changed.')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateCreditNote(string $credit_note_id, #[Schema(type: 'object', additionalProperties: true)] array $changes): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $creditNote = $this->find(CreditNote::class, $credit_note_id, 'Credit note');

        if ($creditNote->getStatus() !== CreditNoteStatus::Draft) {
            throw new ToolCallException('An issued credit note is as fixed as the invoice it corrects and cannot be changed.');
        }

        if (\array_key_exists('discount_type', $changes) || \array_key_exists('discount_value', $changes) || \array_key_exists('invoice_taxes', $changes)) {
            throw new ToolCallException('A credit note takes the discount of the invoice it credits; discount and invoice_taxes cannot be set on it.');
        }

        $this->editor->apply($creditNote, $changes, ['credit_note_date', 'reason']);

        if (\array_key_exists('credit_note_date', $changes)) {
            $creditNote->setCreditNoteDate($this->editor->date($changes['credit_note_date'], 'credit_note_date') ?? throw new ToolCallException('credit_note_date cannot be empty.'));
        }

        if (\array_key_exists('reason', $changes)) {
            $creditNote->setReason(CreditReason::tryFrom(\is_string($changes['reason']) ? $changes['reason'] : '') ?? throw new ToolCallException(sprintf(
                'Invalid reason. Valid values: %s.',
                implode(', ', array_map(static fn (CreditReason $case): string => $case->value, CreditReason::cases())),
            )));
        }

        $this->editor->recalculate($creditNote);

        return $this->normalizer->normalize($creditNote);
    }

    /**
     * Change a recurring invoice: what the next invoices it makes will say.
     * Invoices already made keep what they were made with.
     *
     * @param string               $recurring_id Recurring invoice ULID
     * @param array<string, mixed> $changes      Any of: date_end (ISO-8601 or null), subject, terms, notes, discount_type,
     *                                           discount_value, lines, contact_ids
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_recurring_invoice', description: 'Change a recurring invoice for the invoices it will make next: changes is an object with any of date_end, subject, terms, notes, discount_type, discount_value, lines (replaces all lines), contact_ids.')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateRecurringInvoice(string $recurring_id, #[Schema(type: 'object', additionalProperties: true)] array $changes): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $recurring = $this->find(RecurringInvoice::class, $recurring_id, 'Recurring invoice');

        if (\array_key_exists('invoice_taxes', $changes)) {
            throw new ToolCallException('invoice_taxes cannot be changed on a recurring invoice through MCP.');
        }

        $this->editor->apply($recurring, $changes, ['date_end']);

        if (\array_key_exists('date_end', $changes)) {
            $recurring->setDateEnd($this->editor->date($changes['date_end'], 'date_end'));
        }

        $this->editor->recalculate($recurring);

        return $this->normalizer->normalize($recurring);
    }

    /**
     * Change a purchase not paid yet. Only the keys given are changed.
     *
     * @param string               $bill_id Purchase ULID
     * @param array<string, mixed> $changes Any of: bill_number, issue_date, due_date (ISO-8601 or null), total_amount, tax_amount
     *                                      (minor unit), supply_type, supplier_vat_on_debits, category_id, notes, supplier_id
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_bill', description: 'Change a purchase that is not paid: changes is an object with any of bill_number, issue_date, due_date, total_amount, tax_amount (minor unit), supply_type, supplier_vat_on_debits, category_id, notes, supplier_id.')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateBill(string $bill_id, #[Schema(type: 'object', additionalProperties: true)] array $changes): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $bill = $this->find(Bill::class, $bill_id, 'Purchase');

        if (\in_array($bill->getStatus(), [BillStatus::Paid, BillStatus::Cancelled, BillStatus::Archived], true)) {
            throw new ToolCallException(sprintf('A purchase that is "%s" cannot be changed through MCP.', $bill->getStatus()->value));
        }

        $vatExempt = $this->systemConfig->isVatExempt();

        foreach ($changes as $key => $value) {
            match ($key) {
                'bill_number' => $bill->setBillNumber($value === null ? null : (string) $value),
                'issue_date' => $bill->setIssueDate($this->editor->date($value, 'issue_date')),
                'due_date' => $bill->setDueDate($this->editor->date($value, 'due_date')),
                'notes' => $bill->setNotes($value === null ? null : (string) $value),
                'total_amount' => $bill->setTotalAmount($this->amount($value, 'total_amount') ?? throw new ToolCallException('total_amount cannot be empty.')),
                'tax_amount' => $vatExempt && $value !== null && $value !== 0 && $value !== '0'
                    ? throw new ToolCallException('The company is in VAT franchise: a purchase takes no tax_amount.')
                    : $bill->setTaxAmount($this->amount($value, 'tax_amount')),
                'supply_type' => $bill->setSupplyType(SupplyType::tryFrom(\is_string($value) ? $value : '') ?? throw new ToolCallException('supply_type must be "services" or "goods".')),
                'supplier_vat_on_debits' => $bill->setSupplierVatOnDebits((bool) $value),
                'category_id' => $bill->setCategory($this->purchaseCategory($value)),
                'supplier_id' => $bill->setSupplier($this->supplier($value)),
                default => throw new ToolCallException(sprintf('Unknown field "%s". Accepted: bill_number, issue_date, due_date, total_amount, tax_amount, supply_type, supplier_vat_on_debits, category_id, notes, supplier_id.', (string) $key)),
            };
        }

        $this->entityManager->flush();

        return $this->normalizer->normalize($bill);
    }

    /**
     * Delete a document never handed over: a draft invoice, quote or credit
     * note, a draft purchase, or a recurring invoice. Anything issued is kept,
     * as the law asks; cancel it instead (apply_invoice_transition "cancel"
     * after a credit note, for an invoice).
     *
     * @param string $kind One of: invoice, quote, credit_note, bill, recurring_invoice
     * @param string $id   Document ULID
     *
     * @return array{deleted: bool, kind: string, id: string}
     */
    #[McpTool(name: 'delete_document', description: 'Delete a document that was never issued: a draft invoice, quote, credit note or purchase, or a recurring invoice (kind: invoice, quote, credit_note, bill, recurring_invoice). Issued documents are kept.')]
    #[McpScopeRequired(McpScope::Write)]
    public function deleteDocument(string $kind, string $id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $document = match ($kind) {
            'invoice' => $this->find(Invoice::class, $id, 'Invoice'),
            'quote' => $this->find(Quote::class, $id, 'Quote'),
            'credit_note' => $this->find(CreditNote::class, $id, 'Credit note'),
            'bill' => $this->find(Bill::class, $id, 'Purchase'),
            'recurring_invoice' => $this->find(RecurringInvoice::class, $id, 'Recurring invoice'),
            default => throw new ToolCallException('kind must be one of: invoice, quote, credit_note, bill, recurring_invoice.'),
        };

        $draft = match (true) {
            $document instanceof Invoice => \in_array($document->getStatus(), [InvoiceStatus::New, InvoiceStatus::Draft], true),
            $document instanceof Quote => \in_array($document->getStatus(), [QuoteStatus::New, QuoteStatus::Draft], true),
            $document instanceof CreditNote => $document->getStatus() === CreditNoteStatus::Draft,
            $document instanceof Bill => $document->getStatus() === BillStatus::Draft,
            $document instanceof RecurringInvoice => true,
        };

        if (! $draft) {
            throw new ToolCallException('Only a draft can be deleted: this document has been issued and is kept. Cancel it instead.');
        }

        try {
            $this->entityManager->remove($document);
            $this->entityManager->flush();
        } catch (DocumentMustBeKept $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }

        return ['deleted' => true, 'kind' => $kind, 'id' => $id];
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

    private function amount(mixed $value, string $field): ?BigInteger
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return BigInteger::of(\is_int($value) || \is_string($value) ? $value : throw new ToolCallException(''));
        } catch (MathException | ToolCallException) {
            throw new ToolCallException(sprintf('%s must be a whole number in the minor unit (e.g. cents).', $field));
        }
    }

    private function purchaseCategory(mixed $value): ?Category
    {
        if ($value === null || $value === '') {
            return null;
        }

        $category = $this->categoryRepository->find(UlidParser::parse((string) $value, 'category_id'));

        if (! $category instanceof Category || ! $category->supports(CategoryUsage::Purchase)) {
            throw new ToolCallException(sprintf('Purchase category %s not found (list_categories with usage "purchase").', (string) $value));
        }

        return $category;
    }

    private function supplier(mixed $value): Client
    {
        $supplier = $this->entityManager->getRepository(Client::class)->find(UlidParser::parse((string) $value, 'supplier_id'));

        if (! $supplier instanceof Client) {
            throw new ToolCallException(sprintf('Supplier %s not found.', (string) $value));
        }

        $supplier->setIsSupplier(true);

        return $supplier;
    }
}
