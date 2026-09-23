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
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\LedgerFeeder;
use Augias\AccountingBundle\Service\LedgerTaxSplitter;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
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
use function uniqid;

/**
 * A disbursement reaches the bank but not the book of receipts.
 *
 * Money advanced on the client's behalf and invoiced back at cost is not
 * turnover (CGI art. 267-II-2°): no contributions on it, no threshold used up,
 * no VAT. The invoice already carries it apart; this is where the books stop
 * counting it.
 *
 * The invoice used throughout: 1 000 € of fees at 20 %, so 1 200 €, plus a
 * 500 € disbursement — 1 700 € asked of the client.
 */
#[CoversClass(LedgerFeeder::class)]
#[CoversClass(LedgerTaxSplitter::class)]
#[Group('functional')]
final class DisbursementLedgerTest extends KernelTestCase
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
        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
        $config->set(AccountingSettings::VAT_EXEMPT, '0');

        $this->client = ClientFactory::createOne(['name' => 'Johnston PLC', 'currencyCode' => 'EUR']);
    }

    public function testAnInvoicePaidInFullBooksTheFeesAlone(): void
    {
        $invoice = $this->mixedInvoice();

        self::assertSame('170000', (string) $invoice->getTotal(), 'The client owes the disbursement.');

        $this->pay($invoice, 170_000);

        $entry = $this->soleEntry();

        self::assertSame('120000', (string) $entry->getAmount());
        self::assertSame('100000', (string) $entry->getNetAmount());
        self::assertSame('20000', (string) $entry->getTaxAmount());
        self::assertStringContainsString('500', $entry->getLabel(), 'The bank shows 1 700 €; the entry has to say where 500 € went.');
    }

    /**
     * Pro rata, as the tax already is: half the invoice paid is half of each
     * thing on it, disbursement included.
     */
    public function testAPartialPaymentLeavesOutItsShareOfTheDisbursement(): void
    {
        $this->pay($this->mixedInvoice(), 85_000);

        $entry = $this->soleEntry();

        self::assertSame('60000', (string) $entry->getAmount());
        self::assertSame('50000', (string) $entry->getNetAmount());
        self::assertSame('10000', (string) $entry->getTaxAmount());
    }

    /**
     * Two halves come to the whole: nothing of the disbursement is lost to
     * rounding or counted twice.
     */
    public function testInstalmentsAddUpToTheFees(): void
    {
        $invoice = $this->mixedInvoice();

        $this->pay($invoice, 85_000);
        $this->pay($this->reload($invoice), 85_000);

        $total = 0;

        foreach ($this->entries() as $entry) {
            $total += (int) (string) $entry->getAmount();
        }

        self::assertSame(120_000, $total);
    }

    /**
     * An invoice of nothing but disbursements still leaves a trace — the
     * money did come in — but a trace of zero, with the reason on it.
     */
    public function testAnInvoiceOfDisbursementsOnlyBooksNothing(): void
    {
        $invoice = $this->invoice([$this->disbursementLine(50_000)]);

        $this->pay($invoice, 50_000);

        $entry = $this->soleEntry();

        self::assertSame('0', (string) $entry->getAmount());
        self::assertFalse($entry->hasTax(), 'No taxed line, so no split to record.');
        self::assertStringContainsString('500', $entry->getLabel());
    }

    /**
     * Paying more than was asked covers the disbursement once. The excess was
     * not advanced for anyone.
     */
    public function testAnOverpaymentLeavesOutTheDisbursementOnce(): void
    {
        $this->pay($this->mixedInvoice(), 180_000);

        self::assertSame('130000', (string) $this->soleEntry()->getAmount());
    }

    /**
     * The VAT return reads the breakdown, so this is what it declares: the
     * fees as the taxable base, and not a cent of the advance.
     */
    public function testTheTaxBaseIgnoresTheDisbursement(): void
    {
        $this->pay($this->mixedInvoice(), 170_000);

        $breakdown = $this->soleEntry()->getTaxBreakdown();

        self::assertNotNull($breakdown);
        self::assertCount(1, $breakdown);
        self::assertSame('100000', $breakdown[0]['base']);
        self::assertSame('20000', $breakdown[0]['tax']);
    }

    /**
     * An invoice without disbursements keeps the label it always had, so
     * nothing changes in books that never used the feature.
     */
    public function testAnInvoiceWithoutDisbursementsIsBookedAsBefore(): void
    {
        $this->pay($this->invoice([$this->feeLine()]), 120_000);

        $entry = $this->soleEntry();

        self::assertSame('120000', (string) $entry->getAmount());
        self::assertStringNotContainsString('500', $entry->getLabel());
    }

    /**
     * A reversal takes back what was booked, not what went back through the
     * gateway: the disbursement never entered the book, so it cannot leave it.
     */
    public function testAReversedPaymentTakesBackOnlyTheFees(): void
    {
        $payment = $this->pay($this->mixedInvoice(), 170_000);

        $payment = $this->entityManager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(Payment::class, $payment);
        $payment->setStatus(PaymentStatus::Refunded);
        $this->entityManager->flush();

        $reversal = $this->entryFrom(LedgerEntrySource::InvoiceRefund);

        self::assertSame('-120000', (string) $reversal->getAmount());
        self::assertSame('-100000', (string) $reversal->getNetAmount());
        self::assertSame('-20000', (string) $reversal->getTaxAmount());
        self::assertStringContainsString('500', $reversal->getLabel());
    }

    /**
     * A credit note that gives a disbursement back gives back no revenue for
     * that part — it was never revenue to begin with.
     */
    public function testARefundedDisbursementIsNotTakenOutOfRevenue(): void
    {
        $creditNote = new CreditNote();
        $creditNote->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $creditNote->setCreditNoteId('AV-' . uniqid());
        $creditNote->setReason(CreditReason::Cancellation);
        $creditNote->setCreditNoteDate(new DateTimeImmutable('2026-05-02'));
        $creditNote->setStatus(CreditNoteStatus::Issued);
        $creditNote->setCompany($this->entityManager->find(Company::class, $this->company->getId()));
        $creditNote->addLine(new CreditNoteLine()->setDescription('Fees')->setPrice(100_000)->setQty(1)->addTax($this->vat()));
        $creditNote->addLine(new CreditNoteLine()->setDescription('Advance')->setPrice(50_000)->setQty(1)->setDisbursement(true));

        $this->entityManager->persist($creditNote);
        $this->entityManager->flush();

        self::getContainer()->get(CreditNoteAllocator::class)
            ->allocate($creditNote, AllocationKind::Refund, 170_000);

        $entry = $this->soleEntry();

        self::assertSame('-120000', (string) $entry->getAmount());
        self::assertSame('-100000', (string) $entry->getNetAmount());
        self::assertSame('-20000', (string) $entry->getTaxAmount());
    }

    private function mixedInvoice(): Invoice
    {
        return $this->invoice([$this->feeLine(), $this->disbursementLine(50_000)]);
    }

    private function feeLine(): Line
    {
        return new Line()->setDescription('Consulting')->setPrice(100_000)->setQty(1)->addTax($this->vat());
    }

    private function disbursementLine(int $amount): Line
    {
        return new Line()->setDescription('Screen bought for the client')->setPrice($amount)->setQty(1)->setDisbursement(true);
    }

    /**
     * @param list<Line> $lines
     */
    private function invoice(array $lines): Invoice
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-01-05'));

        foreach ($lines as $line) {
            $invoice->addLine($line);
        }

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function reload(Invoice $invoice): Invoice
    {
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }

    private function pay(Invoice $invoice, int $amount): Payment
    {
        $payment = new Payment();
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode('EUR');
        $invoice->addPayment($payment);
        $payment->setClient($invoice->getClient());
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted(new DateTimeImmutable('2026-02-10'));
        $payment->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function vat(): LineTax
    {
        return new LineTax()
            ->setNameSnapshot('VAT')
            ->setRateSnapshot('20')
            ->setTypeSnapshot(TaxType::Exclusive)
            ->setCategorySnapshot(TaxCategory::Standard);
    }

    private function soleEntry(): LedgerEntry
    {
        $entries = $this->entries();

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
    private function entries(): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(LedgerEntryRepository::class)->findBy([], ['entryDate' => 'ASC', 'created' => 'ASC']);
    }
}
