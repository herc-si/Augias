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
use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\QuoteBundle\Entity\Quote;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\Result\CalculationResult;
use Augias\TaxBundle\Calculator\Result\InvoiceLevelBreakdown;
use Augias\TaxBundle\Calculator\Result\LineBreakdown;
use Augias\TaxBundle\Calculator\Result\TaxSummaryRow;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use function array_fill;
use function array_filter;
use function array_keys;
use function array_pop;
use function count;

/**
 * Orchestrates {@see LineTaxCalculator} and {@see InvoiceTaxCalculator}, returning a
 * single {@see CalculationResult} that {@see \Augias\CoreBundle\Billing\TotalCalculator}
 * uses to populate {@see BaseInvoice}/{@see Quote} totals.
 */
final readonly class TaxCalculator implements TaxCalculatorInterface
{
    public function __construct(
        private LineTaxCalculator $lineTaxCalculator,
        private InvoiceTaxCalculator $invoiceTaxCalculator,
        private SystemConfig $systemConfig,
    ) {
    }

    /**
     * @throws MathException
     */
    public function calculate(BaseInvoice | Quote $document, ?CalculationOptions $options = null): CalculationResult
    {
        $options ??= CalculationOptions::defaults();

        // A company outside the scope of VAT charges none, whatever rates
        // happen to be configured or still attached to an old line. Answering
        // here rather than at each caller is deliberate: this is the single
        // place both the stored totals and the rendered breakdown come from, so
        // there is no way for the two to disagree.
        if ($this->systemConfig->isVatExempt()) {
            return $this->withoutTax($document);
        }

        $rounder = new Rounder($options->rounding);

        $subTotal = BigDecimal::zero();
        $disbursementTotal = BigDecimal::zero();
        $lines = [];
        $lineBreakdowns = [];

        foreach ($document->getLines() as $line) {
            $line->updateTotal();

            $lines[] = $line;
            $breakdown = $this->lineTaxCalculator->calculateLine($line, $rounder);
            $lineBreakdowns[] = $breakdown;

            // A disbursement is owed — it is in the total — but it is not part
            // of the subtotal, because the subtotal is what a document-level
            // rate and a percentage discount are computed from. Money advanced
            // in the client's name must come back to the euro: a 20% rate or a
            // 10% discount reaching it would send back more or less than was
            // paid out, and the operation stops being a disbursement.
            if ($line->isDisbursement()) {
                $disbursementTotal = $disbursementTotal->plus($breakdown->lineSubtotal);
                continue;
            }

            $subTotal = $subTotal->plus($breakdown->lineSubtotal);
        }

        // A discount on the document lowers the price, and so the base the
        // tax is charged on (CGI art. 267-II-1°): each line takes its share
        // of it before its tax is worked out. It used to come off the total
        // after tax, which charged VAT on money the client never paid.
        $discount = $document->getDiscount()->amountOn($subTotal);
        $shares = $this->discountShares($lines, $lineBreakdowns, $discount, $subTotal);

        $total = BigDecimal::zero();
        $totalLineTax = BigDecimal::zero();
        $perLineSummary = [];

        foreach ($lineBreakdowns as $index => $breakdown) {
            $breakdown = $this->lineTaxCalculator->discountLine($lines[$index], $breakdown, $shares[$index], $rounder);
            $lineBreakdowns[$index] = $breakdown;

            $total = $total->plus($breakdown->lineTotal);
            $totalLineTax = $totalLineTax->plus($breakdown->lineTax);

            if ($lines[$index]->isDisbursement()) {
                continue;
            }

            foreach ($breakdown->taxRows as $row) {
                $perLineSummary[] = $row;
            }
        }

        $invoiceLevel = $this->invoiceTaxCalculator->calculateInvoiceLevel(
            $document,
            $subTotal->minus($discount),
            $totalLineTax,
            $rounder
        );

        $summaryRows = $this->mergeSummaryRows([...$perLineSummary, ...$invoiceLevel->taxRows]);

        $total = $total->plus($invoiceLevel->totalInvoiceLevelTax);

        return new CalculationResult(
            subTotal: $subTotal,
            totalLineTax: $totalLineTax,
            total: $total,
            lineBreakdowns: $lineBreakdowns,
            invoiceLevelBreakdown: $invoiceLevel,
            summaryRows: $summaryRows,
            disbursementTotal: $disbursementTotal,
            discount: $discount,
        );
    }

    /**
     * Aggregate {@see TaxSummaryRow}s with the same identity (name + rate + type +
     * category + compound flag) into a single row whose amount is the sum.
     *
     * @param list<TaxSummaryRow> $rows
     * @return list<TaxSummaryRow>
     *
     * @throws MathException
     */
    private function mergeSummaryRows(array $rows): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $key = sprintf(
                '%s|%s|%s|%s|%d|%s|%s',
                $row->name,
                $row->rate,
                $row->type->value,
                $row->category->value,
                $row->compound ? 1 : 0,
                $row->direction->value,
                $row->note ?? '',
            );

            if (! isset($merged[$key])) {
                $merged[$key] = $row;
                continue;
            }

            $existing = $merged[$key];
            $merged[$key] = new TaxSummaryRow(
                name: $existing->name,
                rate: $existing->rate,
                category: $existing->category,
                type: $existing->type,
                compound: $existing->compound,
                amount: $existing->amount->plus($row->amount),
                sequence: $existing->sequence,
                direction: $existing->direction,
                note: $existing->note,
            );
        }

        return array_values($merged);
    }

    /**
     * Subtotals and totals still have to be right — only the tax is gone, and
     * with it every summary row, so no template prints an empty tax block.
     *
     * @throws MathException
     */
    private function withoutTax(BaseInvoice | Quote $document): CalculationResult
    {
        $subTotal = BigDecimal::zero();
        $disbursementTotal = BigDecimal::zero();
        $lines = [];
        $lineBreakdowns = [];

        foreach ($document->getLines() as $line) {
            $line->updateTotal();
            $amount = BigNumber::of($line->getTotal())->toBigDecimal();
            $lines[] = $line;

            // One per line, tax at nothing, as for a liable company: whoever
            // reads the result pairs breakdowns with lines by position. Left
            // empty, the e-invoice found no breakdown for any line and sent an
            // invoice with none — every invoice of a company in franchise was
            // rejected from 07/09/2026, when VAT started disappearing here.
            $lineBreakdowns[] = new LineBreakdown(
                $amount,
                $amount,
                BigDecimal::zero(),
                [],
                $line->isDisbursement() ? SupplyType::Services : $line->getSupplyType(),
            );

            // Still kept apart with no tax in sight. A company in franchise en
            // base charges none either way, but a disbursement is not its
            // turnover, and the figure the books and the ceiling are read from
            // is the subtotal — so the separation matters most exactly here.
            if ($line->isDisbursement()) {
                $disbursementTotal = $disbursementTotal->plus($amount);
                continue;
            }

            $subTotal = $subTotal->plus($amount);
        }

        // No tax for the discount to lower, but it still comes off the price —
        // and off each line's net, which is what e-reporting declares.
        $discount = $document->getDiscount()->amountOn($subTotal);

        foreach ($this->discountShares($lines, $lineBreakdowns, $discount, $subTotal) as $index => $share) {
            $breakdown = $lineBreakdowns[$index];
            $net = $breakdown->lineSubtotal->minus($share);
            $lineBreakdowns[$index] = new LineBreakdown($breakdown->lineSubtotal, $net, BigDecimal::zero(), [], $breakdown->supplyType, $net);
        }

        return new CalculationResult(
            subTotal: $subTotal,
            totalLineTax: BigDecimal::zero(),
            total: $subTotal->minus($discount)->plus($disbursementTotal),
            lineBreakdowns: $lineBreakdowns,
            invoiceLevelBreakdown: InvoiceLevelBreakdown::empty(),
            summaryRows: [],
            disbursementTotal: $disbursementTotal,
            discount: $discount,
        );
    }

    /**
     * Each line's share of the discount, in whole cents, in proportion to
     * its net. The last line sold takes what is left, so the shares add up
     * to the discount. Disbursements take none: nothing comes off money
     * advanced for the client.
     *
     * @param list<LineInterface> $lines
     * @param list<LineBreakdown> $breakdowns
     *
     * @return list<BigDecimal> by line position
     *
     * @throws MathException
     */
    private function discountShares(array $lines, array $breakdowns, BigDecimal $discount, BigDecimal $subTotal): array
    {
        $shares = array_fill(0, count($lines), BigDecimal::zero());

        if ($discount->isZero() || ! $subTotal->isPositive()) {
            return $shares;
        }

        $sold = array_keys(array_filter($lines, static fn (LineInterface $line): bool => ! $line->isDisbursement()));
        $last = array_pop($sold);
        $left = $discount;

        foreach ($sold as $index) {
            $shares[$index] = $discount->multipliedBy($breakdowns[$index]->lineSubtotal)->dividedBy($subTotal, 0, RoundingMode::HalfEven);
            $left = $left->minus($shares[$index]);
        }

        if (null !== $last) {
            $shares[$last] = $left;
        }

        return $shares;
    }
}
