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

namespace Augias\TaxBundle\Calculator;

use Augias\CoreBundle\Entity\LineInterface;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\TaxBundle\Calculator\Result\LineBreakdown;
use Augias\TaxBundle\Calculator\Result\TaxSummaryRow;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxType;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Computes the per-line tax breakdown for an invoice or quote line.
 *
 * Dispatches on each {@see LineTax::$typeSnapshot} value:
 *
 * - {@see TaxType::Inclusive} — the rate is *extracted* from the gross line total,
 *   shrinking the line subtotal. The gross line total stays unchanged.
 *   Compound + Inclusive is rejected by
 *   {@see \Augias\TaxBundle\Validator\Constraints\IncompatibleTaxConfiguration}.
 * - {@see TaxType::Exclusive} — the rate is applied as a percentage on top of the base.
 *   Base is `subtotal` for non-compound or `subtotal + accumulated non-compound tax`
 *   when compound, allowing layered jurisdictions (e.g. Quebec QST on top of GST) to
 *   stack correctly.
 * - {@see TaxType::FlatRate} — a fixed currency amount, `rate_snapshot × 100` to convert
 *   major units to minor units. Compound + FlatRate is rejected by the same constraint.
 *
 * A line marked as a disbursement short-circuits all of this: it carries no
 * tax at all. See {@see LineInterface::isDisbursement()}.
 *
 * Category branches:
 * - {@see TaxCategory::Exempt} rows are skipped entirely.
 * - {@see TaxCategory::ZeroRated}, {@see TaxCategory::OutOfScope},
 *   {@see TaxCategory::ReverseCharge} produce a summary row with amount = 0 (so the
 *   row still appears on the invoice/quote for compliance/clarity).
 *
 * @see \Augias\TaxBundle\Tests\Calculator\LineTaxCalculatorTest
 */
final class LineTaxCalculator
{
    /**
     * @throws MathException
     */
    public function calculateLine(LineInterface $line, Rounder $rounder): LineBreakdown
    {
        $gross = BigNumber::of($line->getTotal())->toBigDecimal();

        // A disbursement bears no tax, whatever rows are attached to the line.
        // The money was advanced in the client's name and is handed back to the
        // euro: charging tax on it would be charging tax on someone else's
        // purchase, and the tax the supplier charged is the client's to deduct,
        // on a document made out to them. Answered here rather than only in the
        // validator so that a line marked after its rates were chosen — or one
        // that arrived through the API — cannot produce tax either.
        if ($line->isDisbursement()) {
            // A disbursement sells nothing, whatever the line says: no tax, and so
            // no question of when it falls due.
            return new LineBreakdown($gross, $gross, BigDecimal::zero(), [], SupplyType::Services);
        }

        $subtotal = $gross;
        $lineTotal = $gross;
        $totalTax = BigDecimal::zero();
        $accumulatedNonCompound = BigDecimal::zero();
        $taxRows = [];

        foreach ($this->orderedTaxes($line) as $lineTax) {
            $category = $lineTax->getCategorySnapshot();

            if ($category === TaxCategory::Exempt) {
                continue;
            }

            if ($category !== TaxCategory::Standard) {
                $taxRows[] = $this->summary($lineTax, BigDecimal::zero());
                continue;
            }

            $type = $lineTax->getTypeSnapshot();
            $rate = BigDecimal::of($lineTax->getRateSnapshot());

            switch ($type) {
                case TaxType::Inclusive:
                    $amount = $this->extractInclusive($gross, $rate, $rounder);
                    $subtotal = $subtotal->minus($amount);
                    break;

                case TaxType::Exclusive:
                    $base = $lineTax->isCompound() ? $subtotal->plus($accumulatedNonCompound) : $subtotal;
                    $amount = $this->applyExclusive($base, $rate, $rounder);
                    $lineTotal = $lineTotal->plus($amount);
                    if (! $lineTax->isCompound()) {
                        $accumulatedNonCompound = $accumulatedNonCompound->plus($amount);
                    }

                    break;

                case TaxType::FlatRate:
                    $amount = $this->applyFlatRate($rate, $rounder);
                    $lineTotal = $lineTotal->plus($amount);
                    if (! $lineTax->isCompound()) {
                        $accumulatedNonCompound = $accumulatedNonCompound->plus($amount);
                    }

                    break;
            }

            $totalTax = $totalTax->plus($amount);
            $taxRows[] = $this->summary($lineTax, $amount);
        }

        return new LineBreakdown($subtotal, $lineTotal, $totalTax, $taxRows, $line->getSupplyType());
    }

    /**
     * Extract the inclusive tax component from a gross figure.
     *
     * @throws MathException
     */
    private function extractInclusive(BigDecimal $gross, BigDecimal $rate, Rounder $rounder): BigDecimal
    {
        $divisor = $rate->dividedBy(100, 10, RoundingMode::HalfEven)->plus(1);
        $net = $gross->dividedBy($divisor, 2, $rounder->getStrategy()->toRoundingMode());

        return $gross->minus($net);
    }

    /**
     * @throws MathException
     */
    private function applyExclusive(BigDecimal $base, BigDecimal $rate, Rounder $rounder): BigDecimal
    {
        return $rounder->round($base->multipliedBy($rate->dividedBy(100, 10, RoundingMode::HalfEven)));
    }

    /**
     * @throws MathException
     */
    private function applyFlatRate(BigDecimal $rate, Rounder $rounder): BigDecimal
    {
        return $rounder->round($rate->multipliedBy(100));
    }

    private function summary(LineTax $lineTax, BigDecimal $amount): TaxSummaryRow
    {
        return new TaxSummaryRow(
            name: (string) $lineTax->getNameSnapshot(),
            rate: $lineTax->getRateSnapshot(),
            category: $lineTax->getCategorySnapshot(),
            type: $lineTax->getTypeSnapshot(),
            compound: $lineTax->isCompound(),
            amount: $amount,
            sequence: $lineTax->getSequence(),
        );
    }

    /**
     * @return list<LineTax>
     */
    private function orderedTaxes(LineInterface $line): array
    {
        $taxes = [];

        foreach ($line->getTaxes() as $lineTax) {
            if ($lineTax instanceof LineTax) {
                $taxes[] = $lineTax;
            }
        }

        usort(
            $taxes,
            static fn (LineTax $a, LineTax $b): int => $a->getSequence() <=> $b->getSequence()
        );

        return $taxes;
    }
}
