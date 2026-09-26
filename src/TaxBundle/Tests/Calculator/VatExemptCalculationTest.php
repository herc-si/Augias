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
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A company outside the scope of VAT charges none, and this is where that is
 * decided. The calculator answers it rather than each caller, because it is the
 * single place both the stored totals and the rendered breakdown come from — if
 * the decision lived at the call sites, an invoice could show one figure and
 * store another.
 */
#[CoversClass(\Augias\TaxBundle\Calculator\TaxCalculator::class)]
final class VatExemptCalculationTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testTaxIsChargedWhenLiable(): void
    {
        $result = $this->calculate($this->invoiceWithTaxedLine());

        self::assertSame('10000', (string) $result->subTotal);
        self::assertSame('2000', (string) $result->totalLineTax);
        self::assertSame('12000', (string) $result->total);
        self::assertNotSame([], $result->summaryRows);
    }

    /**
     * The line still carries its tax — it was attached before the company was
     * marked exempt, or copied from a quote — and it must be ignored rather
     * than trusted.
     */
    public function testNoTaxIsChargedWhenExemptEvenWithATaxedLine(): void
    {
        $invoice = $this->invoiceWithTaxedLine();

        self::getContainer()->get(SystemConfig::class)
            ->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');

        $result = $this->calculate($invoice);

        self::assertSame('10000', (string) $result->subTotal);
        self::assertSame('0', (string) $result->totalLineTax);
        // The total is the subtotal — no tax added on top.
        self::assertSame('10000', (string) $result->total);
        self::assertSame('0', (string) $result->getTotalTax());
        // No summary rows, so no template prints an empty tax block.
        self::assertSame([], $result->summaryRows);
    }

    /**
     * Still one breakdown per line, at no tax: readers pair them with the
     * lines by position, and the e-invoice found none for any line.
     */
    public function testEachLineStillHasItsBreakdownWhenExempt(): void
    {
        $invoice = $this->invoiceWithTaxedLine();

        self::getContainer()->get(SystemConfig::class)
            ->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');

        $result = $this->calculate($invoice);

        self::assertCount(1, $result->lineBreakdowns);
        self::assertSame('10000', (string) $result->lineBreakdowns[0]->lineSubtotal);
        self::assertSame('0', (string) $result->lineBreakdowns[0]->lineTax);
        self::assertSame([], $result->lineBreakdowns[0]->taxRows);
    }

    /**
     * No tax for a discount to lower, but it still comes off the price, and
     * off the line's net — the figure e-reporting declares.
     */
    public function testADiscountStillComesOffWhenExempt(): void
    {
        $invoice = $this->invoiceWithTaxedLine();
        $invoice->setDiscount(new Discount()->setType(Discount::TYPE_PERCENTAGE)->setValue(10));

        self::getContainer()->get(SystemConfig::class)
            ->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');

        $result = $this->calculate($invoice);

        self::assertSame('1000', (string) $result->discount);
        self::assertSame('9000', (string) $result->total);
        self::assertSame('9000', (string) $result->lineBreakdowns[0]->taxableAmount);
    }

    private function calculate(Invoice $invoice): \Augias\TaxBundle\Calculator\Result\CalculationResult
    {
        $calculator = self::getContainer()->get(TaxCalculatorInterface::class);
        self::assertInstanceOf(TaxCalculatorInterface::class, $calculator);

        return $calculator->calculate($invoice);
    }

    private function invoiceWithTaxedLine(): Invoice
    {
        $tax = new Tax();
        $tax->setCompany($this->company)
            ->setName('TVA 20')
            ->setRate(20.0)
            ->setType('Exclusive')
            ->setCategory(TaxCategory::Standard);

        $line = new Line()->setPrice(10000)->setQty(1);
        $line->setCompany($this->company);

        $lineTax = new LineTax();
        $lineTax->setTax($tax);
        $lineTax->snapshotFrom($tax);
        $line->addTax($lineTax);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->addLine($line);

        return $invoice;
    }
}
