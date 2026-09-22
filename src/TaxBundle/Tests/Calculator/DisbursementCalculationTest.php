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

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\SettingsBundle\Repository\SettingsRepository;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\InvoiceTaxCalculator;
use Augias\TaxBundle\Calculator\LineTaxCalculator;
use Augias\TaxBundle\Calculator\TaxCalculator;
use Augias\TaxBundle\Entity\InvoiceTax;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxDirection;
use Augias\TaxBundle\Enum\TaxType;
use Brick\Math\BigDecimal;
use Mockery as M;
use PHPUnit\Framework\TestCase;

/**
 * What the calculator does with a line that re-bills money advanced in the
 * client's name.
 *
 * The rule being checked throughout is the one the law states: a disbursement
 * comes back to the euro. Every figure that could change it — a rate on the
 * line, a rate on the document, a percentage discount — has to miss it, and
 * the total has to contain it all the same, because the client does owe it.
 */
final class DisbursementCalculationTest extends TestCase
{
    private TaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::notInstalledConfig());
    }

    public function testADisbursementIsOwedButIsNotPartOfTheSubtotal(): void
    {
        // 400 € of fees at 20%, plus a 500 € screen bought in the client's name.
        $invoice = new Invoice();
        $invoice->addLine($this->fees(40000));
        $invoice->addLine($this->disbursement(50000));

        $result = $this->calculator->calculate($invoice);

        self::assertTrue($result->subTotal->isEqualTo(BigDecimal::of(40000)), (string) $result->subTotal);
        self::assertTrue($result->disbursementTotal->isEqualTo(BigDecimal::of(50000)), (string) $result->disbursementTotal);
        self::assertTrue($result->totalLineTax->isEqualTo(BigDecimal::of(8000)), (string) $result->totalLineTax);
        self::assertTrue($result->total->isEqualTo(BigDecimal::of(98000)), (string) $result->total);
    }

    public function testARateAttachedToADisbursementProducesNoTax(): void
    {
        $line = $this->disbursement(50000);
        $line->addTax($this->lineTax('VAT 20%', '20.0000'));

        $invoice = new Invoice();
        $invoice->addLine($line);

        $result = $this->calculator->calculate($invoice);

        self::assertTrue($result->totalLineTax->isEqualTo(BigDecimal::zero()), (string) $result->totalLineTax);
        self::assertTrue($result->total->isEqualTo(BigDecimal::of(50000)), (string) $result->total);
        self::assertSame([], $result->summaryRows);
    }

    public function testADocumentLevelRateDoesNotReachTheDisbursement(): void
    {
        $invoice = new Invoice();
        $invoice->addLine($this->plainLine(40000));
        $invoice->addLine($this->disbursement(50000));

        $invoice->addInvoiceTax($this->invoiceTax('VAT 20%', '20.0000'));

        $result = $this->calculator->calculate($invoice);

        // 20% of the 400 € of fees, not of the 900 € on the document.
        self::assertTrue(
            $result->invoiceLevelBreakdown->totalInvoiceLevelTax->isEqualTo(BigDecimal::of(8000)),
            (string) $result->invoiceLevelBreakdown->totalInvoiceLevelTax,
        );
        self::assertTrue($result->total->isEqualTo(BigDecimal::of(98000)), (string) $result->total);
    }

    public function testADisbursementAloneLeavesTheDocumentWithoutTax(): void
    {
        $invoice = new Invoice();
        $invoice->addLine($this->disbursement(50000));

        $result = $this->calculator->calculate($invoice);

        self::assertTrue($result->subTotal->isEqualTo(BigDecimal::zero()), (string) $result->subTotal);
        self::assertTrue($result->getTotalTax()->isEqualTo(BigDecimal::zero()), (string) $result->getTotalTax());
        self::assertTrue($result->total->isEqualTo(BigDecimal::of(50000)), (string) $result->total);
    }

    public function testAnOrdinaryLineIsUnaffected(): void
    {
        $invoice = new Invoice();
        $invoice->addLine($this->fees(40000));

        $result = $this->calculator->calculate($invoice);

        self::assertTrue($result->subTotal->isEqualTo(BigDecimal::of(40000)), (string) $result->subTotal);
        self::assertTrue($result->disbursementTotal->isEqualTo(BigDecimal::zero()), (string) $result->disbursementTotal);
        self::assertTrue($result->total->isEqualTo(BigDecimal::of(48000)), (string) $result->total);
    }

    private function plainLine(int $price): Line
    {
        return new Line()->setPrice($price)->setQty(1);
    }

    private function fees(int $price): Line
    {
        $line = $this->plainLine($price);
        $line->addTax($this->lineTax('VAT 20%', '20.0000'));

        return $line;
    }

    private function disbursement(int $price): Line
    {
        return $this->plainLine($price)->setDisbursement(true);
    }

    private function lineTax(string $name, string $rate): LineTax
    {
        $lineTax = new LineTax();
        $lineTax->setNameSnapshot($name);
        $lineTax->setRateSnapshot($rate);
        $lineTax->setTypeSnapshot(TaxType::Exclusive);
        $lineTax->setCategorySnapshot(TaxCategory::Standard);

        return $lineTax;
    }

    private function invoiceTax(string $name, string $rate): InvoiceTax
    {
        $tax = new Tax();
        $tax->setName($name);
        $tax->setRate((float) $rate);
        $tax->setType(TaxType::Exclusive->value);
        $tax->setCategory(TaxCategory::Standard);

        $invoiceTax = new InvoiceTax();
        $invoiceTax->setTax($tax);
        $invoiceTax->setNameSnapshot($name);
        $invoiceTax->setRateSnapshot($rate);
        $invoiceTax->setCategorySnapshot(TaxCategory::Standard);
        $invoiceTax->setDirection(TaxDirection::Additive);

        return $invoiceTax;
    }

    /**
     * @see InvoiceLevelOrchestrationTest::notInstalledConfig()
     */
    private static function notInstalledConfig(): SystemConfig
    {
        return new SystemConfig(null, M::mock(SettingsRepository::class));
    }
}
