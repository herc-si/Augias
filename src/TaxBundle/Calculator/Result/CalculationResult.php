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

namespace Augias\TaxBundle\Calculator\Result;

use Brick\Math\BigDecimal;

/**
 * Aggregate result of running the orchestrator across an invoice/quote.
 *
 * - {@see $subTotal} is the sum of line subtotals (tax-exclusive), leaving out
 *   disbursement lines — it is the base a document-level rate and a percentage
 *   discount apply to, and neither may reach money advanced in the client's
 *   name.
 * - {@see $disbursementTotal} is what those left-out lines come to. It is part
 *   of {@see $total}, since the client does owe it, and of nothing else.
 * - {@see $totalLineTax} is the sum of all line-level tax components.
 * - {@see $invoiceLevelBreakdown} contains invoice-wide taxes (additive
 *   contribute to {@see $total}; deductive contribute to
 *   {@see $totalWithholding}).
 * - {@see $total} is the gross total before discount, before withholding.
 * - {@see $totalWithholding} is the total deductive amount (TDS).
 * - {@see $amountPayable} is what the client actually owes after withholding
 *   (`$total - $totalWithholding`, before discount).
 * - {@see $summaryRows} aggregates {@see TaxSummaryRow} entries across all
 *   lines plus any invoice-level rows.
 *
 * @phpstan-type LineBreakdowns array<int, LineBreakdown>
 */
final readonly class CalculationResult
{
    public BigDecimal $totalWithholding;

    public BigDecimal $amountPayable;

    public BigDecimal $discount;

    /** The subtotal less the discount: the net the tax was charged on. */
    public BigDecimal $taxableTotal;

    /**
     * @param LineBreakdowns      $lineBreakdowns
     * @param list<TaxSummaryRow> $summaryRows
     */
    public function __construct(
        public BigDecimal $subTotal,
        public BigDecimal $totalLineTax,
        public BigDecimal $total,
        public array $lineBreakdowns,
        public InvoiceLevelBreakdown $invoiceLevelBreakdown,
        public array $summaryRows,
        public BigDecimal $disbursementTotal,
        /**
         * What a discount on the document takes off the subtotal. Already
         * out of the total and of the tax, which is charged on what is left.
         */
        ?BigDecimal $discount = null,
    ) {
        $this->discount = $discount ?? BigDecimal::zero();
        $this->taxableTotal = $this->subTotal->minus($this->discount);
        $this->totalWithholding = $invoiceLevelBreakdown->totalWithholding;
        $this->amountPayable = $this->total->minus($this->totalWithholding);
    }

    public function getTotalTax(): BigDecimal
    {
        return $this->totalLineTax->plus($this->invoiceLevelBreakdown->totalInvoiceLevelTax);
    }
}
