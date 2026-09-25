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

namespace Augias\AccountingBundle\Tests\Service;

use Augias\AccountingBundle\Model\LedgerTaxSplit;
use Augias\AccountingBundle\Model\TaxShare;
use Augias\AccountingBundle\Service\LedgerTaxSplitter;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Augias\TaxBundle\Calculator\CalculationOptions;
use Augias\TaxBundle\Calculator\Result\CalculationResult;
use Augias\TaxBundle\Calculator\Result\InvoiceLevelBreakdown;
use Augias\TaxBundle\Calculator\Result\LineBreakdown;
use Augias\TaxBundle\Calculator\Result\TaxSummaryRow;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxDirection;
use Augias\TaxBundle\Enum\TaxType;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Separating the tax out of what was actually received.
 *
 * Amounts are minor units throughout, as everywhere else in the books.
 */
#[CoversClass(LedgerTaxSplitter::class)]
final class LedgerTaxSplitterTest extends TestCase
{
    public function testAnInvoiceWithNoTaxAtAllSplitsIntoNothing(): void
    {
        // A company in franchise en base. Recording a zero would claim the sale
        // was taxable and bore no tax, which is a different thing to declare.
        $split = $this->splitter(
            subTotal: 100_000,
            lines: [],
        )->forInvoicePayment($this->invoice(100_000), BigInteger::of(100_000));

        self::assertNull($split);
    }

    /**
     * A hand-written entry has no document to read the tax off, so the rate is
     * applied to the money that moved — and the tax comes *out* of it. 120 €
     * received at 20 % contains 20 € of tax; it does not attract 24 €.
     */
    public function testAManualEntryContainsItsTaxRatherThanAttractingIt(): void
    {
        $split = $this->splitter()->forManualEntry(BigInteger::of(120_000), self::tax(20.0));

        self::assertSame('20000', (string) $split->tax);
        self::assertSame('100000', (string) $split->net);
        self::assertCount(1, $split->shares);
        self::assertSame('20.0000', $split->shares[0]->rate);
        self::assertSame('100000', (string) $split->shares[0]->base);
        self::assertSame(TaxCategory::Standard, $split->shares[0]->category);
    }

    /**
     * Net plus tax is the amount that moved, whatever the rate does to the
     * arithmetic. 5.5 % of 105.50 € is not a round figure, and the two parts
     * still have to add back up to what was received.
     */
    public function testAManualEntrySplitAddsBackUpToTheAmount(): void
    {
        $split = $this->splitter()->forManualEntry(BigInteger::of(10_550), self::tax(5.5));

        self::assertSame('550', (string) $split->tax);
        self::assertSame('10000', (string) $split->net);
        self::assertSame('10550', (string) $split->net->plus($split->tax));
    }

    /**
     * A refund is negative money, and the tax given back with it is negative
     * too — otherwise the return would show tax collected on a refund.
     */
    public function testAManualEntryCarriesTheSignOfTheAmount(): void
    {
        $split = $this->splitter()->forManualEntry(BigInteger::of(-120_000), self::tax(20.0));

        self::assertSame('-20000', (string) $split->tax);
        self::assertSame('-100000', (string) $split->net);
    }

    /**
     * Zero-rated is not "no tax". The operation is declared, on a line of its
     * own, so the share is kept with a tax of zero rather than dropped — which
     * is what tells it apart from an operation outside the scope of VAT.
     */
    public function testAZeroRatedManualEntryStillRecordsItsShare(): void
    {
        $split = $this->splitter()->forManualEntry(BigInteger::of(100_000), self::tax(0.0, TaxCategory::ZeroRated));

        self::assertSame('0', (string) $split->tax);
        self::assertSame('100000', (string) $split->net);
        self::assertCount(1, $split->shares);
        self::assertSame(TaxCategory::ZeroRated, $split->shares[0]->category);
    }

    public function testAPaymentInFullCarriesTheWholeTax(): void
    {
        $split = $this->splitter(
            subTotal: 100_000,
            lines: [[100_000, [['20.0000', 20_000]]]],
        )->forInvoicePayment($this->invoice(120_000), BigInteger::of(120_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('20000', (string) $split->tax);
        self::assertSame('100000', (string) $split->net);
        self::assertCount(1, $split->shares);
        self::assertSame('20.0000', $split->shares[0]->rate);
        self::assertSame('100000', (string) $split->shares[0]->base);
    }

    /**
     * Cash accounting: half the money in means half the tax collected, not the
     * whole of it and not none of it.
     */
    public function testAHalfPaymentCarriesHalfTheTax(): void
    {
        $split = $this->splitter(
            subTotal: 100_000,
            lines: [[100_000, [['20.0000', 20_000]]]],
        )->forInvoicePayment($this->invoice(120_000), BigInteger::of(60_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('10000', (string) $split->tax);
        self::assertSame('50000', (string) $split->net);
        self::assertSame('50000', (string) $split->shares[0]->base);
    }

    /**
     * Two rates on one document are two lines on the return, and a partial
     * payment has to carry its share of each.
     */
    public function testEachRateKeepsItsOwnShare(): void
    {
        $split = $this->splitter(
            subTotal: 200_000,
            lines: [
                [100_000, [['20.0000', 20_000]]],
                [100_000, [['5.5000', 5_500]]],
            ],
        )->forInvoicePayment($this->invoice(225_500), BigInteger::of(225_500));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('25500', (string) $split->tax);
        self::assertCount(2, $split->shares);
        self::assertSame(
            [['20.0000', '100000', '20000'], ['5.5000', '100000', '5500']],
            array_map(
                static fn ($share): array => [$share->rate, (string) $share->base, (string) $share->tax],
                $split->shares,
            ),
        );
    }

    /**
     * The invariant the type exists for: whatever the rounding does to the
     * shares, net plus tax is the amount that moved, and the shares add up to
     * exactly those two figures. A cent short here is a cent the return and the
     * books disagree by.
     */
    public function testTheSharesAlwaysAddUpToTheAmountThatMoved(): void
    {
        // A third of an odd total, across two rates: every division here leaves
        // a remainder.
        $split = $this->splitter(
            subTotal: 33_333,
            lines: [
                [11_111, [['20.0000', 2_222]]],
                [22_222, [['5.5000', 1_222]]],
            ],
        )->forInvoicePayment($this->invoice(36_777), BigInteger::of(12_259));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);

        $tax = BigInteger::zero();
        $base = BigInteger::zero();

        foreach ($split->shares as $share) {
            $tax = $tax->plus($share->tax);
            $base = $base->plus($share->base);
        }

        self::assertSame((string) $split->tax, (string) $tax, 'The tax shares have to add up to the tax.');
        self::assertSame((string) $split->net, (string) $base, 'The bases have to add up to the net.');
        self::assertSame('12259', (string) $split->net->plus($split->tax), 'Net plus tax is what was received.');
    }

    /**
     * A zero-rated sale is taxable and bears no tax. It still has to be
     * declared, so it is recorded — with its base, which no rate could recover
     * from a tax of zero.
     */
    public function testAZeroRatedSaleIsRecordedWithItsBase(): void
    {
        $split = $this->splitter(
            subTotal: 80_000,
            lines: [[80_000, [['0.0000', 0, TaxCategory::ZeroRated]]]],
        )->forInvoicePayment($this->invoice(80_000), BigInteger::of(80_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('0', (string) $split->tax);
        self::assertSame('80000', (string) $split->net);
        self::assertSame(TaxCategory::ZeroRated, $split->shares[0]->category);
        self::assertSame('80000', (string) $split->shares[0]->base);
    }

    /**
     * Withholding is not tax the company collected, and it does not belong in
     * what it declares having collected.
     */
    public function testWithholdingIsNotCountedAsTaxCollected(): void
    {
        $split = $this->splitter(
            subTotal: 100_000,
            lines: [[100_000, [['20.0000', 20_000]]]],
            invoiceLevel: [['5.0000', 5_000, TaxCategory::Standard, TaxDirection::Deductive]],
        )->forInvoicePayment($this->invoice(120_000), BigInteger::of(120_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('20000', (string) $split->tax);
        self::assertCount(1, $split->shares);
    }

    /**
     * Paying more than was asked for does not invent tax that was never
     * invoiced; the excess is net and nothing else.
     */
    public function testAnOverpaymentAddsNoTax(): void
    {
        $split = $this->splitter(
            subTotal: 100_000,
            lines: [[100_000, [['20.0000', 20_000]]]],
        )->forInvoicePayment($this->invoice(120_000), BigInteger::of(130_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('20000', (string) $split->tax);
        self::assertSame('110000', (string) $split->net);
    }

    /**
     * A payment for goods did contain their tax, so it records it — marked as
     * due on issue, so that the return does not take it a second time.
     */
    public function testAPaymentMarksTheGoodsSharesAsDueOnIssue(): void
    {
        $split = $this->splitter(
            subTotal: 200_000,
            lines: [
                [100_000, [['20.0000', 20_000]], SupplyType::Goods],
                [100_000, [['20.0000', 20_000]], SupplyType::Services],
            ],
        )->forInvoicePayment($this->invoice(240_000), BigInteger::of(240_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('40000', (string) $split->tax);
        self::assertSame(
            [['100000', '20000', true], ['100000', '20000', false]],
            array_map(
                static fn (TaxShare $share): array => [(string) $share->base, (string) $share->tax, $share->dueOnIssue],
                $split->shares,
            ),
        );
        self::assertSame(TaxShare::DUE_ON_ISSUE, $split->toArray()[0]['due'] ?? null);
        self::assertArrayNotHasKey('due', $split->toArray()[1]);
    }

    /**
     * On issue, the goods' tax is due in full — nothing has been paid and
     * nothing needs to be — and the services' is not due at all.
     */
    public function testTheIssueCarriesTheWholeGoodsTaxAndNothingElse(): void
    {
        $split = $this->splitter(
            subTotal: 200_000,
            lines: [
                [100_000, [['20.0000', 20_000]], SupplyType::Goods],
                [100_000, [['5.5000', 5_500]], SupplyType::Services],
            ],
        )->forIssue($this->invoice(225_500));

        self::assertInstanceOf(LedgerTaxSplit::class, $split);
        self::assertSame('100000', (string) $split->net);
        self::assertSame('20000', (string) $split->tax);
        self::assertCount(1, $split->shares);
        self::assertSame('20.0000', $split->shares[0]->rate);
        self::assertTrue($split->shares[0]->dueOnIssue);
    }

    public function testADocumentWithoutGoodsHasNothingDueOnIssue(): void
    {
        $split = $this->splitter(
            subTotal: 100_000,
            lines: [[100_000, [['20.0000', 20_000]]]],
        )->forIssue($this->invoice(120_000));

        self::assertNull($split);
    }

    /**
     * A document-level rate reaches goods and services alike, so its tax is
     * shared between them in proportion — and the goods part falls due with
     * the goods.
     */
    public function testADocumentLevelRateIsSharedBetweenGoodsAndServices(): void
    {
        $splitter = $this->splitter(
            subTotal: 400_000,
            lines: [
                [300_000, [], SupplyType::Goods],
                [100_000, [], SupplyType::Services],
            ],
            invoiceLevel: [['20.0000', 80_000]],
        );

        $issue = $splitter->forIssue($this->invoice(480_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $issue);
        self::assertSame('300000', (string) $issue->net);
        self::assertSame('60000', (string) $issue->tax);

        $payment = $splitter->forInvoicePayment($this->invoice(480_000), BigInteger::of(480_000));

        self::assertInstanceOf(LedgerTaxSplit::class, $payment);
        self::assertSame('80000', (string) $payment->tax);
        self::assertSame(
            [['300000', '60000', true], ['100000', '20000', false]],
            array_map(
                static fn (TaxShare $share): array => [(string) $share->base, (string) $share->tax, $share->dueOnIssue],
                $payment->shares,
            ),
        );
    }

    private static function tax(float $rate, TaxCategory $category = TaxCategory::Standard): Tax
    {
        return new Tax()
            ->setName('VAT')
            ->setRate($rate)
            ->setType(Tax::TYPE_INCLUSIVE)
            ->setCategory($category);
    }

    /**
     * The document-side cases build a calculator result to read from;
     * forManualEntry() reads nothing, so it takes the defaults.
     *
     * @param list<array{0: int, 1: list<array{0: string, 1: int, 2?: TaxCategory, 3?: TaxDirection}>, 2?: SupplyType}> $lines
     * @param list<array{0: string, 1: int, 2?: TaxCategory, 3?: TaxDirection}>                         $invoiceLevel
     */
    private function splitter(int $subTotal = 0, array $lines = [], array $invoiceLevel = []): LedgerTaxSplitter
    {
        $breakdowns = [];
        $lineTax = BigDecimal::zero();

        foreach ($lines as $line) {
            [$net, $taxes] = $line;
            $rows = [];

            foreach ($taxes as $tax) {
                $rows[] = $this->row(...$tax);
                $lineTax = $lineTax->plus(BigDecimal::of($tax[1]));
            }

            $breakdowns[] = new LineBreakdown(
                BigDecimal::of($net),
                BigDecimal::of($net),
                BigDecimal::zero(),
                $rows,
                $line[2] ?? SupplyType::Services,
            );
        }

        $invoiceRows = [];
        $invoiceTax = BigDecimal::zero();

        foreach ($invoiceLevel as $tax) {
            $invoiceRows[] = $this->row(...$tax);
            $invoiceTax = $invoiceTax->plus(BigDecimal::of($tax[1]));
        }

        $result = new CalculationResult(
            BigDecimal::of($subTotal),
            $lineTax,
            BigDecimal::of($subTotal)->plus($lineTax),
            $breakdowns,
            [] === $invoiceRows ? InvoiceLevelBreakdown::empty() : new InvoiceLevelBreakdown($invoiceTax, $invoiceRows),
            [],
            BigDecimal::zero(),
        );

        // A fake rather than a stub: what is being tested is what the splitter
        // does with a calculation, so the calculation is handed to it whole.
        return new LedgerTaxSplitter(new class($result) implements TaxCalculatorInterface {
            public function __construct(
                private readonly CalculationResult $result
            ) {
            }

            public function calculate(BaseInvoice | Quote $document, ?CalculationOptions $options = null): CalculationResult
            {
                return $this->result;
            }
        });
    }

    private function row(
        string $rate,
        int $amount,
        TaxCategory $category = TaxCategory::Standard,
        TaxDirection $direction = TaxDirection::Additive,
    ): TaxSummaryRow {
        return new TaxSummaryRow(
            'VAT',
            $rate,
            $category,
            TaxType::Exclusive,
            false,
            BigDecimal::of($amount),
            0,
            $direction,
        );
    }

    private function invoice(int $total): Invoice
    {
        $invoice = new Invoice();
        $invoice->setTotal(BigInteger::of($total));
        $invoice->setPayableAmount(BigInteger::of($total));

        return $invoice;
    }
}
