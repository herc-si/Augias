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

namespace Augias\CoreBundle\Tests\Billing;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\MoneyBundle\Calculator;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\InvoiceTaxCalculator;
use Augias\TaxBundle\Calculator\LineTaxCalculator;
use Augias\TaxBundle\Calculator\TaxCalculator;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Doctrine\ORM\Exception\NotSupported;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TotalCalculatorTest extends KernelTestCase
{
    use DoctrineTestTrait;
    use MockeryPHPUnitIntegration;

    /**
     * @throws MathException
     * @throws NotSupported
     */
    public function testUpdateWithSingleItem(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(1)
            ->setPrice(15000);
        $invoice->addLine($item);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(15000), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(15000), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(15000), $invoice->getBaseTotal());
    }

    public function testUpdateWithSingleItemAndMultipleQtys(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $invoice->addLine($item);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(30000), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
    }

    public function testUpdateWithPercentageDiscount(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $invoice->addLine($item);
        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(15);

        $invoice->setDiscount($discount);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(25500), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(25500), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
    }

    /**
     * The document keeps the two apart, and the discount reaches only the fees.
     *
     * 300 € of work and a 500 € screen advanced in the client's name, less 15%.
     * The discount comes off the work — 45 € — and not off the advance, which
     * has to come back to the euro or stop being a disbursement.
     *
     * @throws MathException
     * @throws NotSupported
     */
    public function testAPercentageDiscountLeavesADisbursementAlone(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $invoice->addLine(new Line()->setQty(1)->setPrice(30000));
        $invoice->addLine(new Line()->setQty(1)->setPrice(50000)->setDisbursement(true));

        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(15);
        $invoice->setDiscount($discount);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of(50000), $invoice->getDisbursementTotal());
        self::assertEquals(BigDecimal::of(75500), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(75500), $invoice->getBalance());
    }

    /**
     * An invoice with nothing advanced carries no disbursement total, so
     * nothing about the figures it was issued with changes.
     *
     * @throws MathException
     * @throws NotSupported
     */
    public function testAnInvoiceWithoutDisbursementsTotalsThemAtZero(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));
        $invoice->addLine(new Line()->setQty(1)->setPrice(15000));

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(0), $invoice->getDisbursementTotal());
        self::assertFalse($invoice->hasDisbursements());
        self::assertEquals(BigDecimal::of(15000), $invoice->getTotal());
    }

    public function testUpdateWithMonetaryDiscount(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne());

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $invoice->addLine($item);
        $discount = new Discount();
        $discount->setType(Discount::TYPE_MONEY);
        $discount->setValue(80);

        $invoice->setDiscount($discount);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(29920), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(29920), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
    }

    public function testUpdateWithTaxIncl(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $tax = new Tax();
        $tax->setType(Tax::TYPE_INCLUSIVE)
            ->setRate(20);

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $item->addTax($lineTax);

        $invoice->addLine($item);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(30000), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBalance());
        self::assertEquals(BigDecimal::of('25000.00'), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of('5000.00'), $invoice->getTax());
    }

    public function testUpdateWithTaxFlat(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $tax = new Tax();
        $tax->setType(Tax::TYPE_FLAT_RATE)
            ->setRate(2);

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $item->addTax($lineTax);

        $invoice->addLine($item);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(30200), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(30200), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of(200), $invoice->getTax());
    }

    public function testUpdateWithTaxExcl(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $tax = new Tax();
        $tax->setType(Tax::TYPE_EXCLUSIVE)
            ->setRate(20);

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $item->addTax($lineTax);

        $invoice->addLine($item);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of('36000'), $invoice->getTotal());
        self::assertEquals(BigDecimal::of('36000'), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of('6000'), $invoice->getTax());
    }

    public function testUpdateWithTaxInclAndPercentageDiscount(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $tax = new Tax();
        $tax->setType(Tax::TYPE_INCLUSIVE)
            ->setRate(20);

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $item->addTax($lineTax);
        $invoice->addLine($item);
        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(15);

        $invoice->setDiscount($discount);

        $updater->calculateTotals($invoice);

        // 30000 gross, of which 5000 is tax. A 15% discount is 4500 off that
        // gross figure, not 3750 off the net one — Calculator::calculateDiscount()
        // states the base is baseTotal + tax. The old expectation of 26250 could
        // only arise from the tax being read as zero, which is what it was before
        // the entity was given its tax ahead of the discount being applied.
        self::assertEquals(BigDecimal::of(25500), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(25500), $invoice->getBalance());
        self::assertEquals(BigDecimal::of('25000.00'), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of('5000.00'), $invoice->getTax());
    }

    public function testUpdateWithTaxExclAndMonetaryDiscount(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $tax = new Tax();
        $tax->setType(Tax::TYPE_EXCLUSIVE)
            ->setRate(20);

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000);
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $item->addTax($lineTax);
        $invoice->addLine($item);
        $discount = new Discount();
        $discount->setType(Discount::TYPE_MONEY);
        $discount->setValue(80);

        $invoice->setDiscount($discount);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of('35920'), $invoice->getTotal());
        self::assertEquals(BigDecimal::of('35920'), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of('6000'), $invoice->getTax());
    }

    public function testUpdateTotalsWithPayments(): void
    {
        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));
        $invoice->setTotal(30000);
        $invoice->setBaseTotal(30000);
        $invoice->setBalance(30000);
        $invoice->setStatus(InvoiceStatus::Pending);

        $item = new Line();
        $item->setQty(2)
            ->setPrice(15000)
            ->setDescription('foobar');
        $invoice->addLine($item);

        $payment = new Payment();
        $payment->setTotalAmount(1000);
        $payment->setStatus(PaymentStatus::Captured);

        $invoice->addPayment($payment);
        $this->em->persist($invoice);
        $this->em->flush();

        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(30000), $invoice->getTotal());
        self::assertEquals(BigDecimal::of(29000), $invoice->getBalance());
        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
    }

    /**
     * Test for the rounding issue that causes RoundingNecessaryException
     * with specific price and tax combinations (issue #1824)
     *
     * @throws MathException
     */
    public function testUpdateWithTaxExclRoundingIssue(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $tax = new Tax();
        $tax->setType(Tax::TYPE_EXCLUSIVE)
            ->setRate(21);

        // Test case 1: 3.32 EUR with 21% tax (problematic case from issue)
        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'EUR']));

        $item = new Line();
        $item->setQty(1)
            ->setPrice(332); // 3.32 EUR (stored as cents)
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $item->addTax($lineTax);

        $invoice->addLine($item);

        $updater->calculateTotals($invoice);

        // Verify that the calculation completes without RoundingNecessaryException
        self::assertEquals(BigDecimal::of(332), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of('70'), $invoice->getTax()); // 3.32 * 0.21 = 0.6972, rounded to 70 cents
        self::assertEquals(BigDecimal::of('402'), $invoice->getTotal()); // 332 + 69.72 = 402 cents

        // Test case 2: 3.33 EUR with 21% tax (another problematic case)
        $invoice2 = new Invoice();
        $invoice2->setClient(ClientFactory::createOne(['currencyCode' => 'EUR']));

        $item2 = new Line();
        $item2->setQty(1)
            ->setPrice(333); // 3.33 EUR (stored as cents)
        $lineTax2 = new LineTax();
        $lineTax2->snapshotFrom($tax);

        $item2->addTax($lineTax2);

        $invoice2->addLine($item2);

        $updater->calculateTotals($invoice2);

        // Verify that the calculation completes without RoundingNecessaryException
        self::assertEquals(BigDecimal::of(333), $invoice2->getBaseTotal());
        self::assertEquals(BigDecimal::of('70'), $invoice2->getTax()); // 3.33 * 0.21 = 0.6993, rounded to 70 cents
        self::assertEquals(BigDecimal::of('403'), $invoice2->getTotal()); // 333 + 69.93 = 403 cents
    }

    /**
     * A percentage discount applies to the tax-inclusive total — that is what
     * Calculator::calculateDiscount() reads off the entity. The tax used to be
     * written to the entity after the discount had been worked out, so the
     * discount was calculated against the previous tax figure, zero on a new
     * invoice, and came out short by the tax portion on every taxed document.
     *
     * 30000 net + 20% = 36000; 15% of that is 5400, leaving 30600. Reading a
     * stale zero tax gives 4500 off and 31500, which is what this asserts
     * against.
     */
    public function testPercentageDiscountAppliesToTheTaxInclusiveTotal(): void
    {
        $updater = new TotalCalculator($this->em->getRepository(Payment::class), new Calculator(), new TaxCalculator(new LineTaxCalculator(), new InvoiceTaxCalculator(), self::getContainer()->get(SystemConfig::class)));

        $invoice = new Invoice();
        $invoice->setClient(ClientFactory::createOne(['currencyCode' => 'USD']));

        $tax = new Tax();
        $tax->setName('TVA 20')->setRate(20.0)->setType('Exclusive')->setCategory(TaxCategory::Standard);

        $item = new Line();
        $item->setQty(2)->setPrice(15000);

        $lineTax = new LineTax();
        $lineTax->setTax($tax);
        $lineTax->snapshotFrom($tax);
        $item->addTax($lineTax);
        $invoice->addLine($item);

        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(15);
        $invoice->setDiscount($discount);

        $updater->calculateTotals($invoice);

        self::assertEquals(BigDecimal::of(30000), $invoice->getBaseTotal());
        self::assertEquals(BigDecimal::of(6000), $invoice->getTax());
        self::assertEquals(BigDecimal::of(30600), $invoice->getTotal());
    }
}
