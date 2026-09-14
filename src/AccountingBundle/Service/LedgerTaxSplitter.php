<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Service;

use Augias\AccountingBundle\Model\LedgerTaxSplit;
use Augias\AccountingBundle\Model\TaxShare;
use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\TaxBundle\Calculator\Result\TaxSummaryRow;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxDirection;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use function array_key_first;
use function array_values;
use function count;

/**
 * Separates the tax out of a payment, the way the books have to record it.
 *
 * Augias keeps cash accounting, so what enters the ledger is money that moved,
 * not an invoice that was raised. A VAT return under the same convention — TVA
 * sur les encaissements — declares the tax contained in what was received, per
 * rate. Neither figure is recoverable from the entry afterwards: the amount is
 * a single sum, and an entry is immutable once its period is sealed. So the
 * split is worked out at the moment the entry is written.
 *
 * A partial payment carries its share of each rate, which is why this
 * pro-rates rather than taking the document's tax whole. The shares are then
 * corrected so they add up exactly: cents lost to rounding go to the largest
 * share, and net plus tax is always the amount that actually moved.
 *
 * @see \Augias\AccountingBundle\Tests\Service\LedgerTaxSplitterTest
 */
final readonly class LedgerTaxSplitter
{
    public function __construct(
        private TaxCalculatorInterface $taxCalculator,
    ) {
    }

    /**
     * Null when tax does not apply at all — a company in franchise en base
     * raises invoices with no tax rows, and recording a zero there would claim
     * the sale was taxable at nothing.
     *
     * @throws MathException
     */
    public function forInvoicePayment(Invoice $invoice, BigNumber $paid): ?LedgerTaxSplit
    {
        return $this->forDocument($invoice, $paid);
    }

    /**
     * The same split, read off a credit note instead.
     *
     * A refund gives back tax that was collected, in the proportions it was
     * collected in — so the arithmetic is the one used for a payment, applied
     * to the other document.
     *
     * @throws MathException
     */
    public function forCreditNoteRefund(CreditNote $creditNote, BigNumber $refunded): ?LedgerTaxSplit
    {
        return $this->forDocument($creditNote, $refunded);
    }

    /**
     * @throws MathException
     */
    private function forDocument(BaseInvoice $document, BigNumber $settled): ?LedgerTaxSplit
    {
        $result = $this->taxCalculator->calculate($document);

        /** @var array<string, array{rate: string, category: TaxCategory, base: BigDecimal, tax: BigDecimal}> $groups */
        $groups = [];

        foreach ($result->lineBreakdowns as $line) {
            foreach ($line->taxRows as $row) {
                // The line's own net is the base the rate applied to. Several
                // taxes on one line each take that same base: compounding one
                // VAT onto another is not a thing this has to model.
                $this->collect($groups, $row, $line->lineSubtotal);
            }
        }

        foreach ($result->invoiceLevelBreakdown->taxRows as $row) {
            // A document-level tax applies to the document's net, not to any
            // one line.
            $this->collect($groups, $row, $result->subTotal);
        }

        if ([] === $groups) {
            return null;
        }

        return $this->prorate($groups, $settled, $this->documentTotal($document));
    }

    /**
     * @param array<string, array{rate: string, category: TaxCategory, base: BigDecimal, tax: BigDecimal}> $groups
     */
    private function collect(array &$groups, TaxSummaryRow $row, BigDecimal $base): void
    {
        // Withholding is not tax the company collected, and an informational
        // row is a mention on a document rather than a figure.
        if ($row->direction !== TaxDirection::Additive) {
            return;
        }

        $key = $row->rate . '|' . $row->category->value;

        $groups[$key] ??= [
            'rate' => $row->rate,
            'category' => $row->category,
            'base' => BigDecimal::zero(),
            'tax' => BigDecimal::zero(),
        ];

        $groups[$key]['base'] = $groups[$key]['base']->plus($base);
        $groups[$key]['tax'] = $groups[$key]['tax']->plus($row->amount);
    }

    /**
     * The figure a payment is a share of.
     *
     * What the client was asked for, which is the payable amount once
     * withholding has been taken off it — that is what they pay, so that is
     * what a partial payment is a fraction of.
     *
     * @throws MathException
     */
    private function documentTotal(BaseInvoice $document): BigDecimal
    {
        $payable = BigDecimal::of($document->getPayableAmount());

        return $payable->isPositive() ? $payable : BigDecimal::of($document->getTotal());
    }

    /**
     * @param array<string, array{rate: string, category: TaxCategory, base: BigDecimal, tax: BigDecimal}> $groups
     *
     * @throws MathException
     */
    private function prorate(array $groups, BigNumber $paid, BigDecimal $total): LedgerTaxSplit
    {
        $paidAmount = BigDecimal::of($paid);

        // An overpayment does not create tax that was never invoiced: the
        // excess is net and nothing else.
        $ratio = $total->isPositive() && $paidAmount->isLessThan($total)
            ? $paidAmount->dividedBy($total, 10, RoundingMode::HalfEven)
            : BigDecimal::one();

        $totalTax = BigDecimal::zero();

        foreach ($groups as $group) {
            $totalTax = $totalTax->plus($group['tax']);
        }

        $tax = $this->round($totalTax->multipliedBy($ratio));
        $net = BigDecimal::of($paid)->toBigInteger()->minus($tax);

        $shares = [];
        $taxSoFar = BigInteger::zero();
        $baseSoFar = BigInteger::zero();

        foreach ($groups as $key => $group) {
            $shares[$key] = new TaxShare(
                $group['rate'],
                $group['category'],
                $this->round($group['base']->multipliedBy($ratio)),
                $this->round($group['tax']->multipliedBy($ratio)),
            );

            $taxSoFar = $taxSoFar->plus($shares[$key]->tax);
            $baseSoFar = $baseSoFar->plus($shares[$key]->base);
        }

        // Rounding each share leaves a cent or two unaccounted for. It goes to
        // the largest share, the one where it is proportionally least wrong,
        // so that the shares add up to the entry's own figures exactly.
        $shares = $this->settle($shares, $tax->minus($taxSoFar), $net->minus($baseSoFar));

        return new LedgerTaxSplit($net, $tax, array_values($shares));
    }

    /**
     * @param array<string, TaxShare> $shares
     *
     * @return array<string, TaxShare>
     */
    private function settle(array $shares, BigInteger $taxResidual, BigInteger $baseResidual): array
    {
        if ([] === $shares) {
            return $shares;
        }

        $largest = array_key_first($shares);

        if (count($shares) > 1) {
            foreach ($shares as $key => $share) {
                if ($share->base->isGreaterThan($shares[$largest]->base)) {
                    $largest = $key;
                }
            }
        }

        $shares[$largest] = new TaxShare(
            $shares[$largest]->rate,
            $shares[$largest]->category,
            $shares[$largest]->base->plus($baseResidual),
            $shares[$largest]->tax->plus($taxResidual),
        );

        return $shares;
    }

    /**
     * @throws MathException
     */
    private function round(BigDecimal $amount): BigInteger
    {
        return $amount->toScale(0, RoundingMode::HalfEven)->toBigInteger();
    }
}
