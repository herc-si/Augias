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

namespace Augias\AccountingBundle\Tests\Functional;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\DeclarationResult;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\LedgerFeeder;
use Augias\AccountingBundle\Service\LedgerTaxSplitter;
use Augias\AccountingBundle\Service\VatReturnCalculator;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function uniqid;

/**
 * VAT on goods falls due on delivery — unless a deposit is paid first, in
 * which case it falls due on receipt, up to the deposit (CGI art. 269, 2-a).
 */
#[CoversClass(LedgerFeeder::class)]
#[CoversClass(LedgerTaxSplitter::class)]
#[Group('functional')]
final class DepositVatTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, ReelNormalRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::SaleOfGoods->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
        $config->set(AccountingSettings::VAT_EXEMPT, '0');

        $this->client = ClientFactory::createOne(['name' => 'Johnston PLC', 'currencyCode' => 'EUR']);
    }

    /**
     * Invoiced in March for delivery in May: the goods fall due in the second
     * quarter, not on the invoice date.
     */
    public function testGoodsFallDueOnTheDeliveryDate(): void
    {
        $this->invoice('2026-03-01', '2026-05-15');

        $issued = $this->entryFrom(LedgerEntrySource::InvoiceIssued);
        self::assertSame('2026-05-15', $issued->getEntryDate()->format('Y-m-d'));

        self::assertSame('0', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('20000', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * Delivered before it is invoiced, as a monthly statement is: due on
     * delivery all the same.
     */
    public function testGoodsDeliveredBeforeTheInvoiceFallDueOnDelivery(): void
    {
        $this->invoice('2026-04-02', '2026-03-28');

        self::assertSame('20000', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('0', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * The case the rule is for: 30 % paid in March, delivered in May, the rest
     * paid in June. The deposit's VAT belongs to March, the rest to May, and
     * nothing is declared twice.
     */
    public function testADepositIsDeclaredOnReceiptAndTheRestOnDelivery(): void
    {
        $invoice = $this->invoice('2026-03-01', '2026-05-15');
        $this->pay($invoice, 36_000, '2026-03-10');
        $this->pay($invoice, 84_000, '2026-06-01');

        self::assertSame('6000', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('14000', (string) $this->vatReturn('2026-05-01')->totalDue());

        $setAside = $this->entryFrom(LedgerEntrySource::DepositReceived);
        self::assertSame(LedgerBook::Sales, $setAside->getBook());
        self::assertSame('2026-05-15', $setAside->getEntryDate()->format('Y-m-d'));
        self::assertSame('-6000', (string) $setAside->getTaxAmount());
    }

    /**
     * Without a delivery date, the invoice date is the delivery, and money
     * received after it is not a deposit.
     */
    public function testAPaymentAfterTheInvoiceDateIsNotADeposit(): void
    {
        $invoice = $this->invoice('2026-03-01', null);
        $this->pay($invoice, 36_000, '2026-03-10');

        self::assertSame('20000', (string) $this->vatReturn('2026-02-01')->totalDue());

        foreach ($this->entries() as $entry) {
            self::assertNotSame(LedgerEntrySource::DepositReceived, $entry->getSource());
        }
    }

    /**
     * A deposit given back paid for nothing: its goods fall due on delivery
     * again, with the rest.
     */
    public function testARefundedDepositPutsItsGoodsBackOnTheDelivery(): void
    {
        $invoice = $this->invoice('2026-03-01', '2026-05-15');
        $payment = $this->pay($invoice, 36_000, '2026-03-10');

        $payment = $this->entityManager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(Payment::class, $payment);
        $payment->setStatus(PaymentStatus::Refunded);
        $this->entityManager->flush();

        self::assertSame('20000', (string) $this->vatReturn('2026-05-01')->totalDue());
        self::assertSame('6000', (string) $this->entryFrom(LedgerEntrySource::DepositRefunded)->getTaxAmount());
    }

    private function invoice(string $date, ?string $delivered): Invoice
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate(new DateTimeImmutable($date));
        $invoice->setDeliveryDate(null === $delivered ? null : new DateTimeImmutable($delivered));
        $invoice->addLine(
            new Line()->setDescription('Screens')->setPrice(100_000)->setQty(1)->setSupplyType(SupplyType::Goods)->addTax(
                new LineTax()
                    ->setNameSnapshot('VAT')
                    ->setRateSnapshot('20')
                    ->setTypeSnapshot(TaxType::Exclusive)
                    ->setCategorySnapshot(TaxCategory::Standard),
            ),
        );

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function pay(Invoice $invoice, int $amount, string $date): Payment
    {
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);

        $payment = new Payment();
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode('EUR');
        $invoice->addPayment($payment);
        $payment->setClient($invoice->getClient());
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted(new DateTimeImmutable($date));
        $payment->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function vatReturn(string $date): DeclarationResult
    {
        $period = self::getContainer()->get(AccountingPeriodManager::class)
            ->periodFor($this->entityManager->find(Company::class, $this->company->getId()), PeriodType::Quarter, new DateTimeImmutable($date));
        $this->entityManager->flush();

        return self::getContainer()->get(VatReturnCalculator::class)->calculate($period, 'EUR');
    }

    private function entryFrom(LedgerEntrySource $source): LedgerEntry
    {
        foreach ($this->entries() as $entry) {
            if ($source === $entry->getSource()) {
                return $entry;
            }
        }

        self::fail('No entry from ' . $source->value . ' was written.');
    }

    /**
     * @return list<LedgerEntry>
     */
    private function entries(): array
    {
        return self::getContainer()->get(LedgerEntryRepository::class)->findBy([], ['entryDate' => 'ASC', 'created' => 'ASC']);
    }
}
