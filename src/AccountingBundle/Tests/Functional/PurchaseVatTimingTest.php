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
use Augias\AccountingBundle\Listener\Doctrine\LedgerFeedListener;
use Augias\AccountingBundle\Model\DeclarationResult;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\LedgerFeeder;
use Augias\AccountingBundle\Service\VatReturnCalculator;
use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Entity\BillPayment;
use Augias\BillBundle\Enum\BillPaymentMethod;
use Augias\BillBundle\Enum\BillStatus;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_filter;
use function array_values;

/**
 * When the VAT on a purchase may be deducted: when it falls due at the
 * supplier (CGI art. 271, I-2). For goods, and for services from a supplier
 * on debits, that is the bill's date; for other services, the payment's.
 */
#[CoversClass(LedgerFeeder::class)]
#[CoversClass(LedgerFeedListener::class)]
#[CoversClass(VatReturnCalculator::class)]
#[Group('functional')]
final class PurchaseVatTimingTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    private Client $supplier;

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

        $this->supplier = ClientFactory::createOne(['name' => 'Fournitures SARL', 'currencyCode' => 'EUR']);
    }

    /**
     * Goods billed in March and paid in April: the deduction belongs to the
     * first quarter, and the payment deducts nothing more.
     */
    public function testGoodsBilledInOneQuarterAndPaidInTheNextAreDeductedInTheFirst(): void
    {
        $bill = $this->bill(SupplyType::Goods);
        $this->pay($bill, '2026-04-10');

        $journal = $this->soleEntry(LedgerBook::Bills);
        self::assertSame(LedgerEntrySource::BillReceived, $journal->getSource());
        self::assertSame('2026-03-20', $journal->getEntryDate()->format('Y-m-d'));
        self::assertSame('20000', (string) $journal->getTaxAmount());

        self::assertSame('-20000', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('0', (string) $this->vatReturn('2026-05-01')->totalDue());

        // The payment still records what it contained.
        self::assertSame('20000', (string) $this->soleEntry(LedgerBook::Purchase)->getTaxAmount());
    }

    /**
     * Unchanged for services: deductible when paid.
     */
    public function testServicesAreDeductedWhenPaid(): void
    {
        $bill = $this->bill(SupplyType::Services);
        $this->pay($bill, '2026-04-10');

        self::assertCount(0, $this->entriesIn(LedgerBook::Bills));
        self::assertSame('0', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('-20000', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * A supplier who opted for debits charges their VAT on invoicing, so it is
     * deductible then, services or not.
     */
    public function testServicesFromASupplierOnDebitsAreDeductedOnTheBillsDate(): void
    {
        $bill = $this->bill(SupplyType::Services, onDebits: true);
        $this->pay($bill, '2026-04-10');

        self::assertSame('-20000', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('0', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * A draft is not a bill in hand yet.
     */
    public function testADraftBillDeductsNothingOnItsDate(): void
    {
        $this->bill(SupplyType::Goods, status: BillStatus::Draft);

        self::assertCount(0, $this->entriesIn(LedgerBook::Bills));
    }

    /**
     * A bill that no longer stands no longer gives a deduction.
     */
    public function testCancellingABillTakesItsDeductionBack(): void
    {
        $bill = $this->bill(SupplyType::Goods);

        $bill = $this->reload($bill);
        $bill->setStatus(BillStatus::Cancelled);
        $this->entityManager->flush();

        $cancelled = array_values(array_filter(
            $this->entriesIn(LedgerBook::Bills),
            static fn (LedgerEntry $entry): bool => $entry->getSource() === LedgerEntrySource::BillCancelled,
        ));

        self::assertCount(1, $cancelled);
        self::assertSame('-20000', (string) $cancelled[0]->getTaxAmount());
        self::assertSame('-120000', (string) $cancelled[0]->getAmount());
    }

    /**
     * Whichever way a bill is edited after the fact, its VAT is deducted once:
     * a bill whose payment already deducted it is not deducted again on
     * receipt when it turns out to have been goods.
     */
    public function testABillPaidAsServicesAndCorrectedToGoodsIsNotDeductedTwice(): void
    {
        $bill = $this->bill(SupplyType::Services);
        $this->pay($bill, '2026-04-10');

        $bill = $this->reload($bill);
        $bill->setSupplyType(SupplyType::Goods);
        $this->entityManager->flush();

        self::assertCount(0, $this->entriesIn(LedgerBook::Bills), 'Already deducted from its payment.');
        self::assertSame('-20000', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    private function bill(SupplyType $type, bool $onDebits = false, BillStatus $status = BillStatus::Pending): Bill
    {
        $bill = new Bill();
        $bill->setSupplier($this->entityManager->find(Client::class, $this->supplier->getId()));
        $bill->setTotalAmount(BigInteger::of(120_000));
        $bill->setTaxAmount(BigInteger::of(20_000));
        $bill->setCurrencyCode('EUR');
        $bill->setIssueDate(new DateTimeImmutable('2026-03-20'));
        $bill->setStatus($status);
        $bill->setSupplyType($type);
        $bill->setSupplierVatOnDebits($onDebits);
        $bill->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $this->entityManager->persist($bill);
        $this->entityManager->flush();

        return $bill;
    }

    private function pay(Bill $bill, string $date): void
    {
        $payment = new BillPayment();
        $this->reload($bill)->addPayment($payment);
        $payment->setAmount(BigInteger::of(120_000));
        $payment->setCurrencyCode('EUR');
        $payment->setPaidDate(new DateTimeImmutable($date));
        $payment->setMethod(BillPaymentMethod::BankTransfer);
        $payment->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();
    }

    private function reload(Bill $bill): Bill
    {
        $bill = $this->entityManager->find(Bill::class, $bill->getId());
        self::assertInstanceOf(Bill::class, $bill);

        return $bill;
    }

    private function vatReturn(string $date): DeclarationResult
    {
        $period = self::getContainer()->get(AccountingPeriodManager::class)
            ->periodFor($this->entityManager->find(Company::class, $this->company->getId()), PeriodType::Quarter, new DateTimeImmutable($date));
        $this->entityManager->flush();

        return self::getContainer()->get(VatReturnCalculator::class)->calculate($period, 'EUR');
    }

    private function soleEntry(LedgerBook $book): LedgerEntry
    {
        $entries = $this->entriesIn($book);

        self::assertCount(1, $entries);

        return $entries[0];
    }

    /**
     * @return list<LedgerEntry>
     */
    private function entriesIn(LedgerBook $book): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(LedgerEntryRepository::class)->findBy(['book' => $book], ['entryDate' => 'ASC', 'created' => 'ASC']);
    }
}
