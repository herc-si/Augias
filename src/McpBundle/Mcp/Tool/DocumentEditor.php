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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\QuoteBundle\Entity\Quote;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Mcp\Exception\ToolCallException;

/**
 * Applies the changes an agent asks for to a document already written: only
 * the keys given are touched, and lines given replace every line. The totals
 * are worked out again afterwards, as the form does on save.
 *
 * Keys every document takes: subject, terms, notes, discount_type and
 * discount_value, lines, contact_ids; invoices and quotes also take
 * invoice_taxes. Each tool adds the dates its document has.
 */
final readonly class DocumentEditor
{
    /**
     * The keys {@see apply()} understands, for the tools to name in their
     * refusal of an unknown key.
     */
    public const array COMMON_KEYS = ['subject', 'terms', 'notes', 'discount_type', 'discount_value', 'lines', 'contact_ids', 'invoice_taxes'];

    public function __construct(
        private LineItemBuilder $lineItemBuilder,
        private InvoiceTaxBuilder $invoiceTaxBuilder,
        private TotalCalculator $totalCalculator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $ownKeys the keys the calling tool handles itself
     */
    public function apply(Invoice | Quote | RecurringInvoice | CreditNote $document, array $changes, array $ownKeys = []): void
    {
        $unknown = array_diff(array_keys($changes), self::COMMON_KEYS, $ownKeys);

        if ($unknown !== []) {
            throw new ToolCallException(sprintf(
                'Unknown field(s): %s. Accepted: %s.',
                implode(', ', $unknown),
                implode(', ', [...self::COMMON_KEYS, ...$ownKeys]),
            ));
        }

        foreach (['subject', 'terms', 'notes'] as $key) {
            if (\array_key_exists($key, $changes)) {
                $value = $changes[$key];

                if ($value !== null && ! \is_string($value)) {
                    throw new ToolCallException(sprintf('%s must be a string or null.', $key));
                }

                match ($key) {
                    'subject' => $document->setSubject($value),
                    'terms' => $document->setTerms($value),
                    'notes' => $document->setNotes($value),
                };
            }
        }

        if (\array_key_exists('discount_type', $changes) || \array_key_exists('discount_value', $changes)) {
            $type = $changes['discount_type'] ?? null;
            $value = $changes['discount_value'] ?? null;

            if ($type !== null && ! \is_string($type)) {
                throw new ToolCallException('discount_type must be "percentage", "money" or null.');
            }

            if ($value !== null && ! \is_int($value) && ! \is_float($value)) {
                throw new ToolCallException('discount_value must be a number.');
            }

            $document->setDiscount($this->lineItemBuilder->buildDiscount($type, $value));
        }

        if (\array_key_exists('lines', $changes)) {
            $this->replaceLines($document, $this->list($changes['lines'], 'lines'));
        }

        if (\array_key_exists('contact_ids', $changes)) {
            $this->replaceContacts($document, $this->list($changes['contact_ids'], 'contact_ids'));
        }

        if (\array_key_exists('invoice_taxes', $changes)) {
            if (! $document instanceof Invoice && ! $document instanceof Quote) {
                throw new ToolCallException('invoice_taxes can only be changed on an invoice or a quote.');
            }

            foreach ($document->getInvoiceTaxes()->toArray() as $invoiceTax) {
                $document->removeInvoiceTax($invoiceTax);
            }

            $this->invoiceTaxBuilder->attach($document, $this->list($changes['invoice_taxes'], 'invoice_taxes'));
        }
    }

    public function recalculate(Invoice | Quote | RecurringInvoice | CreditNote $document): void
    {
        $this->totalCalculator->calculateTotals($document);
        $this->entityManager->flush();
    }

    public function date(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! \is_string($value)) {
            throw new ToolCallException(sprintf('%s must be an ISO-8601 date (YYYY-MM-DD).', $field));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new ToolCallException(sprintf('Invalid %s "%s". Use ISO-8601 format (YYYY-MM-DD).', $field, $value));
        }
    }

    /**
     * @param list<mixed> $lines
     */
    private function replaceLines(Invoice | Quote | RecurringInvoice | CreditNote $document, array $lines): void
    {
        /** @var list<array<string, mixed>> $lines */
        $built = match (true) {
            $document instanceof CreditNote => $this->lineItemBuilder->buildCreditNoteLines($lines),
            $document instanceof Invoice => $this->lineItemBuilder->buildInvoiceLines($lines),
            $document instanceof Quote => $this->lineItemBuilder->buildQuoteLines($lines),
            $document instanceof RecurringInvoice => $this->lineItemBuilder->buildRecurringInvoiceLines($lines),
        };

        foreach ($document->getLines()->toArray() as $line) {
            $document->removeLine($line);
        }

        foreach ($built as $line) {
            $document->addLine($line);
        }
    }

    /**
     * @param list<mixed> $contactIds
     */
    private function replaceContacts(Invoice | Quote | RecurringInvoice | CreditNote $document, array $contactIds): void
    {
        $client = $document->getClient();

        if (! $client instanceof Client) {
            throw new ToolCallException('The document has no client to take contacts from.');
        }

        $contacts = [];

        foreach ($contactIds as $index => $contactId) {
            if (! \is_string($contactId)) {
                throw new ToolCallException(sprintf('contact_ids[%d] must be a string.', $index));
            }

            $contact = $this->entityManager
                ->getRepository(Contact::class)
                ->find(UlidParser::parse($contactId, sprintf('contact_ids[%d]', $index)));

            if (! $contact instanceof Contact || $contact->getClient()?->getId()?->equals($client->getId()) !== true) {
                throw new ToolCallException(sprintf('Contact %s does not belong to this client.', $contactId));
            }

            $contacts[] = $contact;
        }

        foreach ($document->getUsers()->toArray() as $contact) {
            $document->removeUser($contact);
        }

        foreach ($contacts as $contact) {
            $document->addUser($contact);
        }
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $field): array
    {
        if (! \is_array($value) || ! array_is_list($value)) {
            throw new ToolCallException(sprintf('%s must be a list.', $field));
        }

        return $value;
    }
}
