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
use Augias\CoreBundle\Enum\SupplyType;
use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\TaxBundle\Calculator\Result\CalculationResult;
use Augias\TaxBundle\Calculator\Result\TaxSummaryRow;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxDirection;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use function array_filter;
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
 * Less any disbursement it carries. Money advanced on the client's behalf and
 * invoiced back at cost is not the company's turnover (CGI art. 267-II-2°),
 * so it is in the receipt but not in the entry: the tax is still the payment's
 * share of the document's, and the net is what is left of the booked amount.
 *
 * Goods are the exception to all of this. Their VAT falls due when they are
 * delivered, not when they are paid for (CGI art. 269, 2-a), so the shares
 * for goods lines are marked as due on issue: a payment still records them,
 * since the money did contain that tax, but the return takes them from the
 * sales journal, where {@see self::forIssue()} puts the whole of them on the
 * day the document goes out.
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
     * $disbursed is the part of $paid that goes to the disbursements, which
     * the entry leaves out. It changes the net, never the tax: a disbursement
     * line carries none.
     *
     * @throws MathException
     */
    public function forInvoicePayment(Invoice $invoice, BigNumber $paid, ?BigNumber $disbursed = null): ?LedgerTaxSplit
    {
        return $this->forDocument($invoice, $paid, $disbursed);
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
    public function forCreditNoteRefund(CreditNote $creditNote, BigNumber $refunded, ?BigNumber $disbursed = null): ?LedgerTaxSplit
    {
        return $this->forDocument($creditNote, $refunded, $disbursed);
    }

    /**
     * The tax that falls due when the document is issued: the whole of what
     * its goods lines carry, by rate.
     *
     * Whole, not pro-rated — nothing has been paid, and nothing needs to be.
     * Null when the document has no goods, or no tax at all.
     *
     * @throws MathException
     */
    public function forIssue(BaseInvoice $document): ?LedgerTaxSplit
    {
        $groups = array_filter(
            $this->groups($document),
            static fn (array $group): bool => $group['dueOnIssue'],
        );

        if ([] === $groups) {
            return null;
        }

        $net = BigInteger::zero();
        $tax = BigInteger::zero();
        $shares = [];

        foreach ($groups as $group) {
            $share = new TaxShare(
                $group['rate'],
                $group['category'],
                $this->round($group['base']),
                $this->round($group['tax']),
                dueOnIssue: true,
            );

            $net = $net->plus($share->base);
            $tax = $tax->plus($share->tax);
            $shares[] = $share;
        }

        return new LedgerTaxSplit($net, $tax, $shares);
    }

    /**
     * The split for an entry written by hand, from the rate the user picked.
     *
     * There is no document to read the tax off, so the rate is applied to the
     * amount — and the tax is taken *out* of it rather than added to it. What a
     * book entry records is money that actually moved, which is a gross figure
     * whatever convention the rate carries for invoice lines: a receipt of
     * 120 € at 20 % contains 20 € of tax, it does not attract 24 €.
     *
     * One rate, one share. A hand-written entry records one receipt or one
     * payment, and the arithmetic that spreads a settlement across several
     * rates has nothing to spread here — which is also why no residual has to
     * be settled: net plus tax is the amount by construction.
     *
     * A zero rate still produces a share. Zero-rated and exempt operations are
     * declared, on lines of their own, and dropping the share would leave the
     * books unable to tell a zero-rated sale from one outside the scope of VAT
     * — a distinction CGI art. 286-I-3° requires them to make.
     *
     * @throws MathException
     */
    public function forManualEntry(BigNumber $amount, Tax $tax): LedgerTaxSplit
    {
        $rate = BigDecimal::of((string) ($tax->getRate() ?? 0));
        $gross = BigDecimal::of($amount);

        $taxAmount = $rate->isZero()
            ? BigInteger::zero()
            : $this->round($gross->multipliedBy($rate)->dividedBy($rate->plus(100), 10, RoundingMode::HalfEven));

        $net = $gross->toBigInteger()->minus($taxAmount);

        return new LedgerTaxSplit($net, $taxAmount, [
            new TaxShare($rate->toScale(4)->__toString(), $tax->getCategory(), $net, $taxAmount),
        ]);
    }

    /**
     * @throws MathException
     */
    private function forDocument(BaseInvoice $document, BigNumber $settled, ?BigNumber $disbursed): ?LedgerTaxSplit
    {
        $groups = $this->groups($document);

        if ([] === $groups) {
            return null;
        }

        return $this->prorate($groups, $settled, $this->documentTotal($document), $disbursed ?? BigInteger::zero());
    }

    /**
     * The document's tax, grouped by rate, category and when it falls due.
     *
     * @return array<string, array{rate: string, category: TaxCategory, base: BigDecimal, tax: BigDecimal, dueOnIssue: bool}>
     *
     * @throws MathException
     */
    private function groups(BaseInvoice $document): array
    {
        $result = $this->taxCalculator->calculate($document);

        $groups = [];

        foreach ($result->lineBreakdowns as $line) {
            foreach ($line->taxRows as $row) {
                // The line's own net is the base the rate applied to. Several
                // taxes on one line each take that same base: compounding one
                // VAT onto another is not a thing this has to model.
                $this->collect($groups, $row, $line->lineSubtotal, $row->amount, $line->supplyType->isTaxedOnIssue());
            }
        }

        // A document-level tax applies to the document's net, not to any one
        // line — so to its goods and its services alike, in proportion. The
        // goods part falls due on issue with the rest of the goods; without
        // goods on the document this is the whole row, as it always was.
        $goods = $this->goodsSubtotal($result);
        $goodsRatio = $result->subTotal->isPositive() && $goods->isPositive()
            ? $goods->dividedBy($result->subTotal, 10, RoundingMode::HalfEven)
            : BigDecimal::zero();

        foreach ($result->invoiceLevelBreakdown->taxRows as $row) {
            $goodsTax = $row->amount->multipliedBy($goodsRatio);

            if ($goods->isPositive()) {
                $this->collect($groups, $row, $goods, $goodsTax, true);
            }

            if ($result->subTotal->isGreaterThan($goods)) {
                $this->collect($groups, $row, $result->subTotal->minus($goods), $row->amount->minus($goodsTax), false);
            }
        }

        return $groups;
    }

    /**
     * The part of the document's subtotal its goods lines make up.
     *
     * Disbursements are already outside the subtotal and never come in here:
     * their breakdown always reads as services.
     */
    private function goodsSubtotal(CalculationResult $result): BigDecimal
    {
        $goods = BigDecimal::zero();

        foreach ($result->lineBreakdowns as $line) {
            if ($line->supplyType === SupplyType::Goods) {
                $goods = $goods->plus($line->lineSubtotal);
            }
        }

        return $goods;
    }

    /**
     * @param array<string, array{rate: string, category: TaxCategory, base: BigDecimal, tax: BigDecimal, dueOnIssue: bool}> $groups
     */
    private function collect(array &$groups, TaxSummaryRow $row, BigDecimal $base, BigDecimal $tax, bool $dueOnIssue): void
    {
        // Withholding is not tax the company collected, and an informational
        // row is a mention on a document rather than a figure.
        if ($row->direction !== TaxDirection::Additive) {
            return;
        }

        $key = $row->rate . '|' . $row->category->value . '|' . ($dueOnIssue ? TaxShare::DUE_ON_ISSUE : '');

        $groups[$key] ??= [
            'rate' => $row->rate,
            'category' => $row->category,
            'base' => BigDecimal::zero(),
            'tax' => BigDecimal::zero(),
            'dueOnIssue' => $dueOnIssue,
        ];

        $groups[$key]['base'] = $groups[$key]['base']->plus($base);
        $groups[$key]['tax'] = $groups[$key]['tax']->plus($tax);
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
     * @param array<string, array{rate: string, category: TaxCategory, base: BigDecimal, tax: BigDecimal, dueOnIssue: bool}> $groups
     *
     * @throws MathException
     */
    private function prorate(array $groups, BigNumber $paid, BigDecimal $total, BigNumber $disbursed): LedgerTaxSplit
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
        $net = BigDecimal::of($paid)->toBigInteger()->minus(BigDecimal::of($disbursed)->toBigInteger())->minus($tax);

        $shares = [];
        $taxSoFar = BigInteger::zero();
        $baseSoFar = BigInteger::zero();

        foreach ($groups as $key => $group) {
            $shares[$key] = new TaxShare(
                $group['rate'],
                $group['category'],
                $this->round($group['base']->multipliedBy($ratio)),
                $this->round($group['tax']->multipliedBy($ratio)),
                $group['dueOnIssue'],
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
            $shares[$largest]->dueOnIssue,
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
