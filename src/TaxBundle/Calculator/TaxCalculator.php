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
        $total = BigDecimal::zero();
        $totalLineTax = BigDecimal::zero();
        $lineBreakdowns = [];
        $perLineSummary = [];

        foreach ($document->getLines() as $line) {
            $line->updateTotal();

            $breakdown = $this->lineTaxCalculator->calculateLine($line, $rounder);

            $lineBreakdowns[] = $breakdown;
            $total = $total->plus($breakdown->lineTotal);
            $totalLineTax = $totalLineTax->plus($breakdown->lineTax);

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

            foreach ($breakdown->taxRows as $row) {
                $perLineSummary[] = $row;
            }
        }

        $invoiceLevel = $this->invoiceTaxCalculator->calculateInvoiceLevel(
            $document,
            $subTotal,
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
        $lineBreakdowns = [];

        foreach ($document->getLines() as $line) {
            $line->updateTotal();
            $amount = BigNumber::of($line->getTotal())->toBigDecimal();

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

        return new CalculationResult(
            subTotal: $subTotal,
            totalLineTax: BigDecimal::zero(),
            total: $subTotal->plus($disbursementTotal),
            lineBreakdowns: $lineBreakdowns,
            invoiceLevelBreakdown: InvoiceLevelBreakdown::empty(),
            summaryRows: [],
            disbursementTotal: $disbursementTotal,
        );
    }
}
