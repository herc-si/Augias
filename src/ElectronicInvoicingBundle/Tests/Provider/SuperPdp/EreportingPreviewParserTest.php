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

namespace Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp;

use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\EreportingPreviewParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Previews as SUPER PDP's sandbox returned them on 12/10/2026.
 */
#[CoversClass(EreportingPreviewParser::class)]
final class EreportingPreviewParserTest extends TestCase
{
    public function testReadsTheSalesOfThePeriod(): void
    {
        $preview = new EreportingPreviewParser()->parse('transaction', <<<'XML'
            <Report>
              <ReportDocument><Id>01a1302e</Id><TypeCode>IN</TypeCode></ReportDocument>
              <TransactionsReport>
                <ReportPeriod><StartDate>20260921</StartDate><EndDate>20260930</EndDate></ReportPeriod>
                <Invoice><ID>FAC-4-2026</ID><IssueDate>20260903</IssueDate></Invoice>
                <Transactions>
                  <Date>20260925</Date>
                  <TransactionsCurrency>EUR</TransactionsCurrency>
                  <CategoryCode>TLB1</CategoryCode>
                  <TaxExclusiveAmount>100.00</TaxExclusiveAmount>
                  <TaxTotal>20.00</TaxTotal>
                </Transactions>
                <Transactions>
                  <Date>20260925</Date>
                  <CategoryCode>TPS1</CategoryCode>
                  <TaxExclusiveAmount>0.00</TaxExclusiveAmount>
                  <TaxTotal>-5.00</TaxTotal>
                </Transactions>
              </TransactionsReport>
            </Report>
            XML);

        self::assertNotNull($preview);
        self::assertSame('2026-09-21', $preview->periodStart->format('Y-m-d'));
        self::assertSame('2026-09-30', $preview->periodEnd->format('Y-m-d'));
        self::assertSame(1, $preview->invoices);
        self::assertCount(2, $preview->rows);
        self::assertSame('TLB1', $preview->rows[0]->category);
        self::assertSame('100.00', $preview->rows[0]->taxExclusiveAmount);
        self::assertSame('20.00', $preview->rows[0]->taxTotal);
        self::assertSame('-5.00', $preview->rows[1]->taxTotal);
    }

    public function testReadsThePaymentsByRate(): void
    {
        $preview = new EreportingPreviewParser()->parse('payment', <<<'XML'
            <Report>
              <PaymentsReport>
                <ReportPeriod><StartDate>20260901</StartDate><EndDate>20260930</EndDate></ReportPeriod>
                <Transactions>
                  <Payment>
                    <Date>20260925</Date>
                    <SubTotals><TaxPercent>10.00</TaxPercent><CurrencyCode>EUR</CurrencyCode><Amount>55.000000</Amount></SubTotals>
                    <SubTotals><TaxPercent>20.00</TaxPercent><CurrencyCode>EUR</CurrencyCode><Amount>-60.000000</Amount></SubTotals>
                  </Payment>
                </Transactions>
              </PaymentsReport>
            </Report>
            XML);

        self::assertNotNull($preview);
        self::assertSame('2026-09-01', $preview->periodStart->format('Y-m-d'));
        self::assertCount(1, $preview->rows);
        self::assertSame(['10.00' => '55.00', '20.00' => '-60.00'], $preview->rows[0]->byRate);
    }

    /**
     * SUPER PDP answers 204 when nothing is in the period yet.
     */
    public function testNothingInThePeriodIsNoPreview(): void
    {
        self::assertNull(new EreportingPreviewParser()->parse('payment', ''));
        self::assertNull(new EreportingPreviewParser()->parse('transaction', 'not xml'));
        self::assertNull(new EreportingPreviewParser()->parse('payment', '<Report><TransactionsReport/></Report>'));
    }
}
