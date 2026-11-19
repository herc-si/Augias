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

use Augias\CatalogBundle\Entity\Product;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Line as InvoiceLine;
use Augias\InvoiceBundle\Entity\RecurringInvoiceLine;
use Augias\QuoteBundle\Entity\Line as QuoteLine;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * Converts the array shape tools receive for line items + discount into
 * domain objects that Invoice / Quote / RecurringInvoice can consume.
 */
final readonly class LineItemBuilder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<InvoiceLine>
     */
    public function buildInvoiceLines(array $lines): array
    {
        return $this->buildLines($lines, static fn (): InvoiceLine => new InvoiceLine());
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<QuoteLine>
     */
    public function buildQuoteLines(array $lines): array
    {
        return $this->buildLines($lines, static fn (): QuoteLine => new QuoteLine());
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<CreditNoteLine>
     */
    public function buildCreditNoteLines(array $lines): array
    {
        return $this->buildLines($lines, static fn (): CreditNoteLine => new CreditNoteLine());
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<RecurringInvoiceLine>
     */
    public function buildRecurringInvoiceLines(array $lines): array
    {
        return $this->buildLines($lines, static fn (): RecurringInvoiceLine => new RecurringInvoiceLine());
    }

    /**
     * @param string|null     $type  "percentage" or "money" (or null for no discount)
     * @param int|float|null  $value Numeric discount value (required if $type is set)
     */
    public function buildDiscount(?string $type, int | float | null $value): Discount
    {
        $discount = new Discount();

        if ($type === null) {
            return $discount;
        }

        if ($value === null) {
            throw new ToolCallException('discount_value is required when discount_type is set.');
        }

        if ($type === Discount::TYPE_PERCENTAGE) {
            $discount->setType(Discount::TYPE_PERCENTAGE);
            $discount->setValuePercentage((float) $value);

            return $discount;
        }

        if ($type === Discount::TYPE_MONEY) {
            $discount->setType(Discount::TYPE_MONEY);

            try {
                $discount->setValueMoney(BigDecimal::of((string) $value));
            } catch (Throwable) {
                throw new ToolCallException(sprintf('Invalid discount_value: %s', (string) $value));
            }

            return $discount;
        }

        throw new ToolCallException(sprintf(
            'Invalid discount_type "%s". Expected "%s" or "%s".',
            $type,
            Discount::TYPE_PERCENTAGE,
            Discount::TYPE_MONEY,
        ));
    }

    /**
     * @template T of InvoiceLine|QuoteLine|RecurringInvoiceLine
     *
     * @param list<array<string, mixed>> $lines
     * @param callable(): T              $factory
     *
     * @return list<T>
     */
    private function buildLines(array $lines, callable $factory): array
    {
        if ($lines === []) {
            throw new ToolCallException('At least one line item is required.');
        }

        $built = [];

        foreach ($lines as $index => $data) {
            if (! \is_array($data)) {
                throw new ToolCallException(sprintf('Line item #%d must be an object.', $index));
            }

            $product = $this->product($data['product_id'] ?? null, $index);
            $note = (bool) ($data['note'] ?? false);

            // A catalogue entry fills what the caller leaves out, as the
            // picker does on the form: text, price, unit, kind and tax.
            $description = $data['description'] ?? ($product instanceof Product ? $this->productDescription($product) : null);
            $price = $data['price'] ?? $product?->getSalePrice()?->__toString() ?? ($note ? 0 : null);
            $qty = $data['qty'] ?? ($data['quantity'] ?? null);

            if (! \is_string($description) || $description === '') {
                throw new ToolCallException(sprintf('Line item #%d requires a non-empty "description" (or a "product_id").', $index));
            }

            if ($price === null) {
                throw new ToolCallException(sprintf('Line item #%d requires a "price" (in the minor unit, e.g. cents), or a "product_id".', $index));
            }

            if ($qty === null) {
                $qty = $note ? 0 : 1;
            }

            $line = $factory();
            $line->setDescription($description);

            try {
                $line->setPrice(BigDecimal::of($this->toExactString($price)));
            } catch (Throwable) {
                throw new ToolCallException(sprintf('Line item #%d has an invalid "price": %s', $index, $this->describe($price)));
            }

            try {
                $line->setQty(BigDecimal::of($this->toExactString($qty)));
            } catch (Throwable) {
                throw new ToolCallException(sprintf('Line item #%d has an invalid "qty": %s', $index, $this->describe($qty)));
            }

            $line->setSupplyType(
                $product instanceof Product && ! isset($data['supply_type'])
                    ? $product->getType()->supplyType()
                    : $this->supplyType($data['supply_type'] ?? null, $index),
            );
            $line->setUnit(
                $product instanceof Product && ! isset($data['unit'])
                    ? $product->getUnit()
                    : $this->unit($data['unit'] ?? null, $index),
            );

            $disbursement = (bool) ($data['disbursement'] ?? false);

            if ($disbursement && ! $line instanceof InvoiceLine) {
                throw new ToolCallException(sprintf('Line item #%d: only an invoice or a credit note can carry a disbursement (débours).', $index));
            }

            if (($note || isset($data['position'])) && $line instanceof RecurringInvoiceLine) {
                throw new ToolCallException(sprintf('Line item #%d: a recurring invoice line cannot be a note or take a position.', $index));
            }

            if (! $line instanceof RecurringInvoiceLine) {
                $line->setNote($note);
                $line->setPosition(\is_int($data['position'] ?? null) ? $data['position'] : $index);
            }

            if ($line instanceof InvoiceLine) {
                $line->setDisbursement($disbursement);
            }

            if ($product instanceof Product && ! isset($data['taxes']) && ! isset($data['tax_id']) && $product->getTax() instanceof Tax && ! $disbursement && ! $note) {
                $data['tax_id'] = $product->getTax()->getId()->toRfc4122();
            }

            if ($disbursement && (! empty($data['taxes']) || ! empty($data['tax_id']))) {
                throw new ToolCallException(sprintf('Line item #%d: a disbursement (débours) is re-invoiced at cost and carries no tax.', $index));
            }

            $this->attachTaxes($line, $data, $index);

            if (! $line instanceof RecurringInvoiceLine) {
                // A note is text only: no price, no quantity, no tax.
                $line->neutraliseNote();
            }

            $built[] = $line;
        }

        return $built;
    }

    private function product(mixed $value, int $index): ?Product
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! \is_string($value)) {
            throw new ToolCallException(sprintf('Line item #%d: "product_id" must be a string.', $index));
        }

        $product = $this->entityManager
            ->getRepository(Product::class)
            ->find(UlidParser::parse($value, sprintf('line[%d].product_id', $index)));

        if (! $product instanceof Product) {
            throw new ToolCallException(sprintf('Line item #%d: product %s not found.', $index, $value));
        }

        return $product;
    }

    /**
     * The catalogue keeps name and description apart; a document line has one
     * text field, so they are joined as on the form.
     */
    private function productDescription(Product $product): string
    {
        $description = trim((string) $product->getDescription());

        return $description === '' ? (string) $product->getName() : $product->getName() . "\n" . $description;
    }

    /**
     * A plain count unless the caller says otherwise, as on the form.
     */
    private function unit(mixed $value, int $index): QuantityUnit
    {
        if ($value === null) {
            return QuantityUnit::Unit;
        }

        $unit = \is_string($value) ? QuantityUnit::tryFrom($value) : null;

        if (! $unit instanceof QuantityUnit) {
            throw new ToolCallException(sprintf(
                'Line item #%d has an invalid "unit": %s. Expected one of: %s.',
                $index,
                $this->describe($value),
                implode(', ', array_map(static fn (QuantityUnit $case): string => '"' . $case->value . '"', QuantityUnit::cases())),
            ));
        }

        return $unit;
    }

    /**
     * Services unless the caller says otherwise, as on the form.
     */
    private function supplyType(mixed $value, int $index): SupplyType
    {
        if ($value === null) {
            return SupplyType::Services;
        }

        $type = \is_string($value) ? SupplyType::tryFrom($value) : null;

        if (! $type instanceof SupplyType) {
            throw new ToolCallException(sprintf('Line item #%d has an invalid "supply_type": %s. Expected "goods" or "services".', $index, $this->describe($value)));
        }

        return $type;
    }

    /**
     * A JSON number reaches a tool as a float. `(string)` would serialise it at the host's
     * `precision` ini setting, so the same tool call could resolve to different digits from
     * one host to the next; %.14G pins that, matching
     * {@see \Augias\ApiBundle\Serializer\Normalizer\BigIntegerNormalizer}.
     *
     * Anything that is not a number is handed to BigDecimal untouched, so that malformed
     * input fails there and is reported as a ToolCallException.
     */
    private function toExactString(mixed $value): mixed
    {
        return \is_float($value) ? sprintf('%.14G', $value) : $value;
    }

    /**
     * Tools are handed raw JSON, so a value that failed to parse can be an array or an
     * object; casting those to string for the error message would emit a warning of its own.
     */
    private function describe(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : \get_debug_type($value);
    }

    /**
     * Attach LineTax entities to the line.
     *
     * Accepts either:
     *  - `taxes`: list of {tax_id, sequence?, compound?} (preferred for multi-tax)
     *  - `tax_id`: single ULID (legacy single-tax shorthand)
     *
     * @param array<string, mixed> $data
     */
    private function attachTaxes(InvoiceLine | QuoteLine | RecurringInvoiceLine $line, array $data, int $index): void
    {
        $taxesInput = $data['taxes'] ?? null;

        if (\is_array($taxesInput) && $taxesInput !== []) {
            foreach (array_values($taxesInput) as $position => $taxData) {
                if (! \is_array($taxData)) {
                    throw new ToolCallException(sprintf('line[%d].taxes[%d] must be an object.', $index, $position));
                }

                $taxId = $taxData['tax_id'] ?? null;

                if (! \is_string($taxId) || $taxId === '') {
                    throw new ToolCallException(sprintf('line[%d].taxes[%d].tax_id is required.', $index, $position));
                }

                $tax = $this->entityManager
                    ->getRepository(Tax::class)
                    ->find(UlidParser::parse($taxId, sprintf('line[%d].taxes[%d].tax_id', $index, $position)));

                if (! $tax instanceof Tax) {
                    throw new ToolCallException(sprintf('line[%d].taxes[%d] tax %s not found.', $index, $position, $taxId));
                }

                $lineTax = new LineTax();
                $lineTax->snapshotFrom($tax);
                $lineTax->setSequence((int) ($taxData['sequence'] ?? $position));
                $lineTax->setCompound((bool) ($taxData['compound'] ?? $tax->isCompound()));

                $line->addTax($lineTax);
            }

            return;
        }

        $taxId = $data['tax_id'] ?? null;

        if (\is_string($taxId) && $taxId !== '') {
            $tax = $this->entityManager
                ->getRepository(Tax::class)
                ->find(UlidParser::parse($taxId, sprintf('line[%d].tax_id', $index)));

            if (! $tax instanceof Tax) {
                throw new ToolCallException(sprintf('Line item #%d tax %s not found.', $index, $taxId));
            }

            $lineTax = new LineTax();
            $lineTax->snapshotFrom($tax);

            $line->addTax($lineTax);
        }
    }
}
