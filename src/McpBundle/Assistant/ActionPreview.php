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

namespace Augias\McpBundle\Assistant;

use Augias\CatalogBundle\Entity\Product;
use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Entity\Category;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Augias\TaxBundle\Entity\Tax;
use Doctrine\ORM\EntityManagerInterface;
use Locale;
use NumberFormatter;
use Stringable;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use function array_is_list;
use function array_map;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function mb_strimwidth;
use function sprintf;

/**
 * What a proposed change will do, in words the person can check before
 * confirming it: names in place of identifiers, amounts in place of cents,
 * every line of a document. Built from the arguments the model gave, never
 * from what it says about them.
 *
 * Identifiers are looked up through the company filter: one that is not the
 * open company's shows as unknown, and the tool would refuse it anyway.
 *
 * @see \Augias\McpBundle\Tests\Assistant\ActionPreviewTest
 */
final readonly class ActionPreview
{
    /** Arguments that hold an identifier, and what it names. */
    private const array REFERENCES = [
        'client_id' => Client::class,
        'product_id' => Product::class,
        'category_id' => Category::class,
        'tax_id' => Tax::class,
        'address_id' => Address::class,
        'quote_id' => Quote::class,
        'invoice_id' => Invoice::class,
    ];

    /** Arguments holding a code, and where its label is translated. */
    private const array CODES = [
        'unit' => 'catalog.unit.',
        'type' => 'catalog.type.',
        'discount_type' => 'assistant.value.discount_type.',
        'resource' => 'assistant.value.resource.',
    ];

    /** Arguments in the minor unit. */
    private const array AMOUNTS = ['price', 'amount', 'sale_price', 'purchase_price', 'total'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{title: string, details: list<array{label: string, value: string}>}
     */
    public function describe(string $tool, array $arguments): array
    {
        $currency = $this->currency($arguments);
        $details = [];

        foreach ($arguments as $key => $value) {
            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $text = match (true) {
                $key === 'lines' && is_array($value) => $this->lines($value, $currency),
                $key === 'id' && in_array($arguments['resource'] ?? null, ['client', 'contact'], true) => $this->reference($arguments['resource'] === 'client' ? Client::class : Contact::class, $value),
                is_array($value) => $this->fields($value, $currency),
                default => $this->value((string) $key, $value, $currency),
            };

            $details[] = ['label' => $this->label((string) $key), 'value' => $text];
        }

        return ['title' => $this->translator->trans('assistant.action.' . $tool), 'details' => $details];
    }

    /**
     * @param array<mixed> $lines
     */
    private function lines(array $lines, ?string $currency): string
    {
        $rows = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $what = is_string($line['description'] ?? null) && $line['description'] !== ''
                ? $line['description']
                : (isset($line['product_id']) ? $this->reference(Product::class, $line['product_id']) : '?');
            $qty = $line['qty'] ?? $line['quantity'] ?? 1;
            $price = isset($line['price']) ? $this->money($line['price'], $currency) : null;

            $rows[] = sprintf('%s × %s%s', is_scalar($qty) ? (string) $qty : '1', mb_strimwidth($what, 0, 120, '…'), $price !== null ? ' — ' . $price : '');
        }

        return implode("\n", $rows);
    }

    /**
     * @param array<mixed> $fields
     */
    private function fields(array $fields, ?string $currency): string
    {
        if (array_is_list($fields)) {
            return implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '…', $fields));
        }

        $rows = [];
        foreach ($fields as $key => $value) {
            if ($value === null || is_array($value)) {
                continue;
            }

            $rows[] = $this->label((string) $key) . ' : ' . $this->value((string) $key, $value, $currency);
        }

        return implode("\n", $rows);
    }

    private function value(string $key, mixed $value, ?string $currency): string
    {
        if (isset(self::REFERENCES[$key]) && $this->ulid($value) instanceof Ulid) {
            return $this->reference(self::REFERENCES[$key], $value);
        }

        if (in_array($key, self::AMOUNTS, true)) {
            return $this->money($value, $currency) ?? (string) (is_scalar($value) ? $value : '');
        }

        if (isset(self::CODES[$key]) && is_string($value)) {
            $id = self::CODES[$key] . $value;
            $label = $this->translator->trans($id);

            return $label === $id ? $value : $label;
        }

        if (is_bool($value)) {
            return $this->translator->trans($value ? 'assistant.yes' : 'assistant.no');
        }

        return is_scalar($value) ? mb_strimwidth((string) $value, 0, 300, '…') : '…';
    }

    /**
     * @param class-string $class
     */
    private function reference(string $class, mixed $id): string
    {
        $ulid = $this->ulid($id);

        try {
            $entity = $ulid instanceof Ulid ? $this->entityManager->find($class, $ulid) : null;
        } catch (Throwable) {
            $entity = null;
        }

        return match (true) {
            $entity instanceof Quote => (string) ($entity->getQuoteId() ?: $this->translator->trans('assistant.draft_quote')) . ' — ' . $entity->getClient()?->getName(),
            $entity instanceof Invoice => (string) ($entity->getInvoiceId() ?: $this->translator->trans('assistant.draft_invoice')) . ' — ' . $entity->getClient()?->getName(),
            $entity instanceof Stringable => (string) $entity,
            default => $this->translator->trans('assistant.unknown'),
        };
    }

    private function money(mixed $minor, ?string $currency): ?string
    {
        if (! is_numeric($minor)) {
            return null;
        }

        $locale = Locale::getDefault();

        if ($currency !== null) {
            $formatted = NumberFormatter::create($locale, NumberFormatter::CURRENCY)->formatCurrency(((float) $minor) / 100, $currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $formatter = NumberFormatter::create($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return (string) $formatter->format(((float) $minor) / 100);
    }

    /**
     * The currency amounts are in: the one named, or the client's.
     *
     * @param array<string, mixed> $arguments
     */
    private function currency(array $arguments): ?string
    {
        if (is_string($arguments['currency'] ?? null)) {
            return $arguments['currency'];
        }

        $clientId = $this->ulid($arguments['client_id'] ?? null);
        $client = $clientId instanceof Ulid ? $this->entityManager->find(Client::class, $clientId) : null;

        if ($client instanceof Client) {
            return $client->getCurrencyCode();
        }

        foreach ([Invoice::class => 'invoice_id', Quote::class => 'quote_id'] as $class => $key) {
            $documentId = $this->ulid($arguments[$key] ?? null);
            $document = $documentId instanceof Ulid ? $this->entityManager->find($class, $documentId) : null;

            if ($document instanceof Invoice || $document instanceof Quote) {
                return $document->getClient()?->getCurrencyCode();
            }
        }

        return null;
    }

    /**
     * An identifier as the tools write them (RFC 4122) or as ULIDs are; null
     * for anything else, such as a quote number given as `quote_id`.
     */
    private function ulid(mixed $value): ?Ulid
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Ulid::fromString($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function label(string $key): string
    {
        $id = 'assistant.field.' . $key;
        $label = $this->translator->trans($id);

        return $label === $id ? $key : $label;
    }
}
