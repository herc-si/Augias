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
use Augias\AccountingBundle\Entity\AccountingPeriod;
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
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
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
use function array_filter;
use function array_values;
use function uniqid;

/**
 * VAT on goods falls due on delivery, VAT on services on payment (CGI art.
 * 269, 2). A goods invoice raised in March and paid in April is declared in
 * the first quarter; a service in the second.
 */
#[CoversClass(LedgerFeeder::class)]
#[CoversClass(LedgerFeedListener::class)]
#[CoversClass(VatReturnCalculator::class)]
#[CoversClass(AccountingPeriodManager::class)]
#[Group('functional')]
final class GoodsVatLedgerTest extends KernelTestCase
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

    public function testAnInvoiceForGoodsFilesItsVatInTheSalesJournalOnItsDate(): void
    {
        $this->invoice([$this->goods(100_000)], '2026-03-20');

        $entry = $this->soleEntry(LedgerBook::Sales);

        self::assertSame(LedgerEntrySource::InvoiceIssued, $entry->getSource());
        self::assertSame('2026-03-20', $entry->getEntryDate()->format('Y-m-d'));
        self::assertSame('120000', (string) $entry->getAmount());
        self::assertSame('100000', (string) $entry->getNetAmount());
        self::assertSame('20000', (string) $entry->getTaxAmount());
        self::assertSame('Johnston PLC', $entry->getCounterpartyName());
    }

    /**
     * The case the journal exists for: raised in March, paid in April. The
     * tax belongs to the first quarter; the payment in the second brings the
     * money, not the tax a second time.
     */
    public function testGoodsInvoicedInOneQuarterAndPaidInTheNextAreDeclaredInTheFirst(): void
    {
        $invoice = $this->invoice([$this->goods(100_000)], '2026-03-20');
        $this->pay($invoice, 120_000, '2026-04-10');

        $first = $this->vatReturn('2026-02-01');
        self::assertSame('20000', (string) $first->totalDue());
        self::assertSame('100000', (string) $first->turnover);

        $second = $this->vatReturn('2026-05-01');
        self::assertSame('0', (string) $second->totalDue());
        self::assertSame([], $second->lines);

        // The receipt is still in the revenue book, whole: the money came in
        // on that day, and turnover is what came in.
        $receipt = $this->soleEntry(LedgerBook::Revenue);
        self::assertSame('120000', (string) $receipt->getAmount());
        self::assertSame('20000', (string) $receipt->getTaxAmount());
        self::assertSame('0', (string) $receipt->collectedTax());
    }

    public function testAMixedInvoiceDeclaresItsGoodsOnIssueAndItsServicesOnPayment(): void
    {
        $invoice = $this->invoice([$this->goods(100_000), $this->service(50_000)], '2026-03-20');
        $this->pay($invoice, 180_000, '2026-04-10');

        self::assertSame('20000', (string) $this->vatReturn('2026-02-01')->totalDue());

        $second = $this->vatReturn('2026-05-01');
        self::assertSame('10000', (string) $second->totalDue());
        self::assertSame('50000', (string) $second->turnover);
    }

    /**
     * Nothing changes for a company that sells services: nothing goes in the
     * journal, and the tax is declared on payment as it always was.
     */
    public function testAnInvoiceForServicesWritesNothingInTheSalesJournal(): void
    {
        $invoice = $this->invoice([$this->service(100_000)], '2026-03-20');

        self::assertSame([], $this->entriesIn(LedgerBook::Sales));

        $this->pay($this->reload($invoice), 120_000, '2026-04-10');

        self::assertSame('0', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('20000', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * Not issued, not due: a draft is nothing yet.
     */
    public function testADraftInvoiceWritesNothing(): void
    {
        $this->invoice([$this->goods(100_000)], '2026-03-20', InvoiceStatus::Draft);

        self::assertSame([], $this->entries());
    }

    /**
     * A credit note for goods takes their VAT back on its own date (CGI art.
     * 272, 1). The refund that follows moves the money, and only the money:
     * taking the tax back a second time would under-declare by as much.
     */
    public function testACreditNoteForGoodsTakesTheirVatBackOnceOnItsDate(): void
    {
        $this->invoice([$this->goods(100_000)], '2026-03-20');
        $creditNote = $this->creditNote(100_000, '2026-05-02');

        $credit = $this->entryFrom(LedgerEntrySource::CreditNoteIssued);
        self::assertSame(LedgerBook::Sales, $credit->getBook());
        self::assertSame('2026-05-02', $credit->getEntryDate()->format('Y-m-d'));
        self::assertSame('-120000', (string) $credit->getAmount());
        self::assertSame('-20000', (string) $credit->getTaxAmount());

        $creditNote = $this->entityManager->find(CreditNote::class, $creditNote->getId());
        self::assertInstanceOf(CreditNote::class, $creditNote);

        self::getContainer()->get(CreditNoteAllocator::class)
            ->allocate($creditNote, AllocationKind::Refund, 120_000, null, new DateTimeImmutable('2026-05-20'));

        self::assertSame('-20000', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * Not about goods at all: money refunded on a service gives back the VAT
     * it contained, so the return goes down by it, not up.
     */
    public function testARefundOnAServiceTakesItsVatOffTheReturn(): void
    {
        $invoice = $this->invoice([$this->service(100_000)], '2026-04-01');
        $this->pay($invoice, 120_000, '2026-04-10');

        $creditNote = new CreditNote();
        $creditNote->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $creditNote->setCreditNoteId('AV-' . uniqid());
        $creditNote->setReason(CreditReason::Cancellation);
        $creditNote->setCreditNoteDate(new DateTimeImmutable('2026-05-02'));
        $creditNote->setStatus(CreditNoteStatus::Issued);
        $creditNote->setCompany($this->entityManager->find(Company::class, $this->company->getId()));
        $creditNote->addLine(new CreditNoteLine()->setDescription('Installation')->setPrice(50_000)->setQty(1)->addTax($this->vat()));
        $this->entityManager->persist($creditNote);
        $this->entityManager->flush();

        self::getContainer()->get(CreditNoteAllocator::class)
            ->allocate($creditNote, AllocationKind::Refund, 60_000, null, new DateTimeImmutable('2026-05-20'));

        self::assertSame('10000', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * An entry is written once per document, however many times it is saved:
     * a paid invoice is flushed again on every payment.
     */
    public function testSavingTheInvoiceAgainWritesNothingMore(): void
    {
        $invoice = $this->invoice([$this->goods(100_000)], '2026-03-20');
        $this->pay($invoice, 60_000, '2026-04-10');
        $this->pay($this->reload($invoice), 60_000, '2026-04-20');

        self::assertCount(1, $this->entriesIn(LedgerBook::Sales));
        self::assertCount(2, $this->entriesIn(LedgerBook::Revenue));
    }

    /**
     * The quarter's sealed figures agree with its return: the goods' tax is
     * collected there, and the journal adds nothing to turnover — the sale
     * reaches the revenue when it is paid.
     */
    public function testSealingAQuarterFreezesTheGoodsVatButNoTurnover(): void
    {
        $this->invoice([$this->goods(100_000)], '2026-03-20');

        $period = $this->period('2026-02-01');
        self::getContainer()->get(AccountingPeriodManager::class)->close($period, null, new DateTimeImmutable('2026-04-15'));

        $totals = $this->reloadPeriod($period)->getTotals();

        self::assertNotNull($totals);
        self::assertSame('20000', $totals['tax_collected'] ?? null);
        self::assertSame([], $totals['revenue'] ?? null);
    }

    /**
     * @param list<Line> $lines
     */
    private function invoice(array $lines, string $date, InvoiceStatus $status = InvoiceStatus::Pending): Invoice
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $invoice->setStatus($status);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate(new DateTimeImmutable($date));

        foreach ($lines as $line) {
            $invoice->addLine($line);
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function creditNote(int $amount, string $date): CreditNote
    {
        $creditNote = new CreditNote();
        $creditNote->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $creditNote->setCreditNoteId('AV-' . uniqid());
        $creditNote->setReason(CreditReason::Cancellation);
        $creditNote->setCreditNoteDate(new DateTimeImmutable($date));
        $creditNote->setStatus(CreditNoteStatus::Issued);
        $creditNote->setCompany($this->entityManager->find(Company::class, $this->company->getId()));
        $creditNote->addLine(
            new CreditNoteLine()->setDescription('Screens')->setPrice($amount)->setQty(1)
                ->setSupplyType(SupplyType::Goods)->addTax($this->vat()),
        );

        $this->entityManager->persist($creditNote);
        $this->entityManager->flush();

        return $creditNote;
    }

    private function goods(int $price): Line
    {
        return new Line()->setDescription('Screens')->setPrice($price)->setQty(1)
            ->setSupplyType(SupplyType::Goods)->addTax($this->vat());
    }

    private function service(int $price): Line
    {
        return new Line()->setDescription('Installation')->setPrice($price)->setQty(1)->addTax($this->vat());
    }

    private function vat(): LineTax
    {
        return new LineTax()
            ->setNameSnapshot('VAT')
            ->setRateSnapshot('20')
            ->setTypeSnapshot(TaxType::Exclusive)
            ->setCategorySnapshot(TaxCategory::Standard);
    }

    private function pay(Invoice $invoice, int $amount, string $date): void
    {
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
    }

    private function reload(Invoice $invoice): Invoice
    {
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }

    private function period(string $date): AccountingPeriod
    {
        $period = self::getContainer()->get(AccountingPeriodManager::class)
            ->periodFor($this->entityManager->find(Company::class, $this->company->getId()), PeriodType::Quarter, new DateTimeImmutable($date));
        $this->entityManager->flush();

        return $period;
    }

    private function reloadPeriod(AccountingPeriod $period): AccountingPeriod
    {
        $this->entityManager->clear();
        $period = $this->entityManager->find(AccountingPeriod::class, $period->getId());
        self::assertInstanceOf(AccountingPeriod::class, $period);

        return $period;
    }

    private function vatReturn(string $date): DeclarationResult
    {
        return self::getContainer()->get(VatReturnCalculator::class)->calculate($this->period($date), 'EUR');
    }

    private function soleEntry(LedgerBook $book): LedgerEntry
    {
        $entries = $this->entriesIn($book);

        self::assertCount(1, $entries);

        return $entries[0];
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
    private function entriesIn(LedgerBook $book): array
    {
        return array_values(array_filter($this->entries(), static fn (LedgerEntry $entry): bool => $entry->getBook() === $book));
    }

    /**
     * @return list<LedgerEntry>
     */
    private function entries(): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(LedgerEntryRepository::class)->findBy([], ['entryDate' => 'ASC', 'created' => 'ASC']);
    }
}
