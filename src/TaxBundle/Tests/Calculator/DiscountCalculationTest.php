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

namespace Augias\TaxBundle\Tests\Calculator;

use Augias\CoreBundle\Entity\Discount;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\SettingsBundle\Repository\SettingsRepository;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\InvoiceTaxCalculator;
use Augias\TaxBundle\Calculator\LineTaxCalculator;
use Augias\TaxBundle\Calculator\TaxCalculator;
use Augias\TaxBundle\Entity\InvoiceTax;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxDirection;
use Augias\TaxBundle\Enum\TaxType;
use Mockery as M;
use PHPUnit\Framework\TestCase;

/**
 * A discount on the document lowers the price, and so the base VAT is charged
 * on (CGI art. 267-II-1°). It used to come off the total after tax, which
 * charged VAT on money the client never paid.
 */
final class DiscountCalculationTest extends TestCase
{
    private TaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), new SystemConfig(null, M::mock(SettingsRepository::class)));
    }

    public function testTheTaxIsChargedOnTheNetLessTheDiscount(): void
    {
        $invoice = $this->invoice(Discount::TYPE_PERCENTAGE, 10);
        $invoice->addLine($this->line(100000, '20'));

        $result = $this->calculator->calculate($invoice);

        self::assertSame('100000', (string) $result->subTotal);
        self::assertSame('10000', (string) $result->discount);
        self::assertSame('90000', (string) $result->taxableTotal);
        self::assertSame('18000', (string) $result->getTotalTax());
        self::assertSame('108000', (string) $result->total);
    }

    /**
     * Each rate takes its share of the discount, in proportion to the net it
     * is charged on, and the shares come to the discount to the cent.
     */
    public function testADiscountIsSharedBetweenTheRates(): void
    {
        $invoice = $this->invoice(Discount::TYPE_MONEY, 10000);
        $invoice->addLine($this->line(20000, '20'));
        $invoice->addLine($this->line(10000, '5.5'));

        $result = $this->calculator->calculate($invoice);

        // 100 € off 300 €: two thirds on the 20% line, one third on the 5.5%.
        [$standard, $reduced] = $result->lineBreakdowns;
        self::assertSame('20000', (string) $standard->lineSubtotal, 'The line still shows its own price.');
        self::assertSame('13333', (string) $standard->taxableAmount);
        self::assertSame('2667', (string) $standard->lineTax);
        self::assertSame('6667', (string) $reduced->taxableAmount);
        self::assertSame('367', (string) $reduced->lineTax);
        self::assertSame('23034', (string) $result->total);
    }

    public function testADiscountNeverReachesADisbursement(): void
    {
        $invoice = $this->invoice(Discount::TYPE_PERCENTAGE, 10);
        $invoice->addLine($this->line(40000, '20'));
        $invoice->addLine(new Line()->setPrice(50000)->setQty(1)->setDisbursement(true));

        $result = $this->calculator->calculate($invoice);

        self::assertSame('4000', (string) $result->discount, '10% of the fees, not of the screen bought for the client.');
        self::assertSame('50000', (string) $result->lineBreakdowns[1]->taxableAmount);
        // 360 € of fees, 72 € of tax, and the 500 € advanced, whole.
        self::assertSame('93200', (string) $result->total);
    }

    public function testARateOnTheWholeDocumentIsChargedOnTheDiscountedNet(): void
    {
        $invoice = $this->invoice(Discount::TYPE_PERCENTAGE, 10);
        $invoice->addLine(new Line()->setPrice(100000)->setQty(1));
        $invoice->addInvoiceTax(new InvoiceTax()->setNameSnapshot('TVA')->setRateSnapshot('20')->setTypeSnapshot(TaxType::Exclusive)->setCategorySnapshot(TaxCategory::Standard)->setDirection(TaxDirection::Additive));

        $result = $this->calculator->calculate($invoice);

        self::assertSame('18000', (string) $result->invoiceLevelBreakdown->totalInvoiceLevelTax);
        self::assertSame('108000', (string) $result->total);
    }

    public function testADiscountLargerThanTheSaleTakesTheWholeNetAndNoMore(): void
    {
        $invoice = $this->invoice(Discount::TYPE_MONEY, 50000);
        $invoice->addLine($this->line(20000, '20'));

        $result = $this->calculator->calculate($invoice);

        self::assertSame('20000', (string) $result->discount);
        self::assertSame('0', (string) $result->getTotalTax());
        self::assertSame('0', (string) $result->total);
    }

    private function invoice(string $type, int $value): Invoice
    {
        $invoice = new Invoice();
        $invoice->setDiscount(new Discount()->setType($type)->setValue($value));

        return $invoice;
    }

    private function line(int $price, string $rate): Line
    {
        $line = new Line()->setPrice($price)->setQty(1);
        $line->addTax(new LineTax()->setNameSnapshot('TVA ' . $rate)->setRateSnapshot($rate)->setTypeSnapshot(TaxType::Exclusive)->setCategorySnapshot(TaxCategory::Standard));

        return $line;
    }
}
