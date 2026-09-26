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

namespace Augias\ElectronicInvoicingBundle\Tests\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\ElectronicInvoicingBundle\Manager\ReportDataBuilder;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\InvoiceTax;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxDirection;
use Augias\TaxBundle\Enum\TaxType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function count;

/**
 * What e-reporting declares for a sale to a private individual.
 */
#[CoversClass(ReportDataBuilder::class)]
final class ReportDataBuilderTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testAClientWithoutAFrenchNumberOrAVatNumberIsAPrivateIndividual(): void
    {
        $builder = $this->builder();
        $individual = ClientFactory::createOne(['company' => $this->company]);
        $business = ClientFactory::createOne(['company' => $this->company])->setSiren('123456789');

        self::assertTrue($builder->isPrivateIndividual($individual));
        self::assertFalse($builder->isPrivateIndividual($business));
    }

    /**
     * A sale of goods and services is one transaction in each category, each
     * with its VAT by rate and the code for when that VAT fell due.
     */
    public function testAMixedSaleIsOneTransactionPerCategory(): void
    {
        $this->liable();
        $invoice = $this->invoice([[SupplyType::Goods, 10000, '20'], [SupplyType::Services, 5000, '10']]);

        $transactions = $this->builder()->transactions($invoice);

        self::assertCount(2, $transactions);
        [$goods, $services] = $transactions;

        self::assertSame('TLB1', $goods->category);
        self::assertSame('100.00', $goods->taxExclusiveAmount);
        self::assertSame('20.00', $goods->taxTotal);
        self::assertSame(['20.00' => ['taxable' => '100.00', 'tax' => '20.00']], $goods->subtotals);
        self::assertSame('3', $goods->taxDueDateTypeCode);

        self::assertSame('TPS1', $services->category);
        self::assertSame('5.00', $services->taxTotal);
        self::assertSame('432', $services->taxDueDateTypeCode, 'Services: VAT due on payment.');
    }

    /**
     * A company in franchise charges no VAT: its sales are reported as not
     * subject to it, at nothing.
     */
    public function testACompanyInFranchiseReportsItsSalesAsNotSubjectToVat(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');
        $invoice = $this->invoice([[SupplyType::Goods, 10000, null], [SupplyType::Services, 5000, null]]);

        $transactions = $this->builder()->transactions($invoice);

        self::assertCount(1, $transactions);
        self::assertSame('TNT1', $transactions[0]->category);
        self::assertSame('150.00', $transactions[0]->taxExclusiveAmount);
        self::assertSame('0.00', $transactions[0]->taxTotal);
        self::assertNull($this->builder()->payment($this->paid($invoice, 15000)), 'No VAT, no payment to report.');
    }

    /**
     * Half the invoice paid is half of each services rate — tax included.
     * Goods are left out: their VAT fell due on delivery.
     */
    public function testAPaymentReportsItsShareOfTheServices(): void
    {
        $this->liable();
        $invoice = $this->invoice([[SupplyType::Goods, 10000, '20'], [SupplyType::Services, 10000, '20']]);

        $payment = $this->builder()->payment($this->paid($invoice, 12000));

        self::assertNotNull($payment);
        self::assertSame(['20.00' => '60.00'], $payment->amounts);
    }

    /**
     * A rate set on the whole invoice rather than on its lines is VAT all the
     * same: each category carries its share, in proportion to its net, and a
     * payment reports its services' share of it.
     */
    public function testATaxOnTheWholeInvoiceIsSharedBetweenItsCategories(): void
    {
        $this->liable();
        $invoice = $this->invoice([[SupplyType::Goods, 10000, null], [SupplyType::Services, 5000, null]], '20');

        [$goods, $services] = $this->builder()->transactions($invoice);

        self::assertSame('TLB1', $goods->category);
        self::assertSame('100.00', $goods->taxExclusiveAmount);
        self::assertSame('20.00', $goods->taxTotal);
        self::assertSame(['20.00' => ['taxable' => '100.00', 'tax' => '20.00']], $goods->subtotals);

        self::assertSame('TPS1', $services->category);
        self::assertSame(['20.00' => ['taxable' => '50.00', 'tax' => '10.00']], $services->subtotals);

        $payment = $this->builder()->payment($this->paid($invoice, 9000));

        self::assertNotNull($payment);
        self::assertSame(['20.00' => '30.00'], $payment->amounts, 'Half the invoice paid: half of its services, tax included.');
    }

    /**
     * Cents a share cannot carry go to the last line: what is declared adds
     * up to the tax the invoice shows.
     */
    public function testTheSharesOfATaxOnTheWholeInvoiceAddUpToIt(): void
    {
        $this->liable();
        $invoice = $this->invoice([[SupplyType::Services, 3333, null], [SupplyType::Services, 3333, null], [SupplyType::Services, 3334, null]], '5.5');

        [$services] = $this->builder()->transactions($invoice);

        self::assertSame('100.00', $services->taxExclusiveAmount);
        self::assertSame('5.50', $services->taxTotal);
    }

    /**
     * Withholding is not VAT the company collected: it changes nothing here.
     */
    public function testWithholdingOnTheWholeInvoiceIsNotReported(): void
    {
        $this->liable();
        $invoice = $this->invoice([[SupplyType::Services, 10000, '20']], '10', TaxDirection::Deductive);

        [$services] = $this->builder()->transactions($invoice);

        self::assertSame(['20.00' => ['taxable' => '100.00', 'tax' => '20.00']], $services->subtotals);
    }

    public function testNoPaymentIsReportedUnderTheOptionForDebits(): void
    {
        $this->liable();
        $invoice = $this->invoice([[SupplyType::Services, 10000, '20']]);
        $invoice->setVatOnDebits(true);

        self::assertNull($this->builder()->payment($this->paid($invoice, 12000)));
    }

    /**
     * A credit note is the sale going the other way: the same categories and
     * codes, every amount negative, dated when it was issued.
     */
    public function testACreditNoteIsReportedAsANegativeSale(): void
    {
        $this->liable();
        $creditNote = $this->creditNote([[SupplyType::Goods, 10000, '20'], [SupplyType::Services, 5000, '10']]);

        [$goods, $services] = $this->builder()->transactions($creditNote);

        self::assertSame('TLB1', $goods->category);
        self::assertSame('-100.00', $goods->taxExclusiveAmount);
        self::assertSame('-20.00', $goods->taxTotal);
        self::assertSame(['20.00' => ['taxable' => '-100.00', 'tax' => '-20.00']], $goods->subtotals);
        self::assertSame('3', $goods->taxDueDateTypeCode);
        self::assertSame('2026-09-27', $goods->date->format('Y-m-d'));

        self::assertSame('TPS1', $services->category);
        self::assertSame('-5.00', $services->taxTotal);
        self::assertSame('432', $services->taxDueDateTypeCode);
    }

    /**
     * Paying a credit note back is money going out for the services it
     * credits; setting it against another invoice moves none.
     */
    public function testARefundIsANegativePaymentAndAnOffsetIsNone(): void
    {
        $this->liable();
        $creditNote = $this->creditNote([[SupplyType::Goods, 10000, '20'], [SupplyType::Services, 10000, '20']]);

        $refund = $this->builder()->refund($this->allocated($creditNote, AllocationKind::Refund, 12000));

        self::assertNotNull($refund);
        self::assertSame(['20.00' => '-60.00'], $refund->amounts, 'Half the credit note back: half its services, tax included.');
        self::assertSame('2026-09-28', $refund->date->format('Y-m-d'));

        self::assertNull($this->builder()->refund($this->allocated($creditNote, AllocationKind::Offset, 12000)));
    }

    private function builder(): ReportDataBuilder
    {
        return self::getContainer()->get(ReportDataBuilder::class);
    }

    private function liable(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '0');
    }

    /**
     * @param list<array{0: SupplyType, 1: int, 2: string|null}> $lines
     * @param string|null                                          $invoiceRate a tax on the whole invoice
     */
    private function invoice(array $lines, ?string $invoiceRate = null, TaxDirection $direction = TaxDirection::Additive): Invoice
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        self::assertInstanceOf(Client::class, $client);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('B2C-' . count($lines));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-09-25'));

        foreach ($lines as [$type, $price, $rate]) {
            $line = new Line()->setDescription($type->value)->setPrice($price)->setQty(1)->setSupplyType($type);

            if (null !== $rate) {
                $line->addTax(new LineTax()->setNameSnapshot('TVA')->setRateSnapshot($rate)->setTypeSnapshot(TaxType::Exclusive)->setCategorySnapshot(TaxCategory::Standard));
            }

            $invoice->addLine($line->updateTotal());
        }

        if (null !== $invoiceRate) {
            $invoice->addInvoiceTax(new InvoiceTax()->setNameSnapshot('TVA')->setRateSnapshot($invoiceRate)->setTypeSnapshot(TaxType::Exclusive)->setCategorySnapshot(TaxCategory::Standard)->setDirection($direction));
        }

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        return $invoice;
    }

    /**
     * @param list<array{0: SupplyType, 1: int, 2: string|null}> $lines
     */
    private function creditNote(array $lines): CreditNote
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        self::assertInstanceOf(Client::class, $client);

        $creditNote = new CreditNote();
        $creditNote->setCompany($this->company);
        $creditNote->setClient($client);
        $creditNote->setCreditNoteId('AV-' . count($lines));
        $creditNote->setStatus(CreditNoteStatus::Issued);
        $creditNote->setCreditNoteDate(new DateTimeImmutable('2026-09-27'));

        foreach ($lines as [$type, $price, $rate]) {
            $line = new CreditNoteLine()->setDescription($type->value)->setPrice($price)->setQty(1)->setSupplyType($type);

            if (null !== $rate) {
                $line->addTax(new LineTax()->setNameSnapshot('TVA')->setRateSnapshot($rate)->setTypeSnapshot(TaxType::Exclusive)->setCategorySnapshot(TaxCategory::Standard));
            }

            $creditNote->addLine($line->updateTotal());
        }

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($creditNote);
        $entityManager->flush();

        return $creditNote;
    }

    private function allocated(CreditNote $creditNote, AllocationKind $kind, int $amount): CreditNoteAllocation
    {
        $allocation = new CreditNoteAllocation()
            ->setCreditNote($creditNote)
            ->setKind($kind)
            ->setAmount($amount)
            ->setAllocatedOn(new DateTimeImmutable('2026-09-28'));
        $allocation->setCompany($this->company);

        return $allocation;
    }

    private function paid(Invoice $invoice, int $amount): Payment
    {
        $payment = new Payment();
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode('EUR');
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted(new DateTimeImmutable('2026-09-26'));
        $invoice->addPayment($payment);

        return $payment;
    }
}
