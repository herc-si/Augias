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
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\LedgerFeeder;
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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function uniqid;

/**
 * What a credit note does to the books.
 *
 * The short answer is: usually nothing. Under cash-basis books an offset needs
 * no entry — the client pays less on the next invoice and the smaller receipt
 * is already the whole truth. Only money actually leaving gets written down.
 */
#[CoversClass(LedgerFeeder::class)]
#[CoversClass(LedgerFeedListener::class)]
final class CreditNoteLedgerTest extends KernelTestCase
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

        $this->configureRegime();

        $this->client = ClientFactory::createOne(['name' => 'Johnston PLC', 'currencyCode' => 'EUR']);
    }

    /**
     * Raising the document owes the client money, but no money has moved.
     */
    public function testIssuingACreditNoteWritesNothing(): void
    {
        $this->issuedCreditNote(50_000);

        self::assertCount(0, $this->entries());
    }

    /**
     * The heart of it: the client pays less next time, and that smaller receipt
     * is already the correction.
     */
    public function testSettingACreditAgainstAnInvoiceWritesNothing(): void
    {
        $creditNote = $this->issuedCreditNote(50_000);
        $invoice = $this->invoice();

        $this->allocator()->allocate($creditNote, AllocationKind::Offset, 50_000, $invoice);

        self::assertCount(0, $this->entries());
    }

    public function testARefundIsBookedBackOutOfRevenue(): void
    {
        $creditNote = $this->issuedCreditNote(50_000);

        $this->allocator()->allocate(
            $creditNote,
            AllocationKind::Refund,
            50_000,
            null,
            new DateTimeImmutable('2026-05-20'),
        );

        $entries = $this->entries();

        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame(LedgerBook::Revenue, $entry->getBook());
        self::assertSame(LedgerEntrySource::InvoiceRefund, $entry->getSource());
        self::assertSame('-50000', (string) $entry->getAmount());
        self::assertSame('EUR', $entry->getCurrencyCode());
        // This credit note credits no invoice, so there is no receipt to date
        // the refund on and it keeps the day it happened.
        self::assertSame('2026-05-20', $entry->getEntryDate()->format('Y-m-d'));
        self::assertSame(ActivityNature::ServicesBnc, $entry->getActivityNature());
        self::assertSame('Johnston PLC', $entry->getCounterpartyName());
    }

    /**
     * Half now, half never: the books only ever hold what actually went back.
     */
    public function testAPartialRefundBooksOnlyWhatWentBack(): void
    {
        $creditNote = $this->issuedCreditNote(50_000);

        $this->allocator()->allocate($creditNote, AllocationKind::Refund, 20_000);

        $entries = $this->entries();

        self::assertCount(1, $entries);
        self::assertSame('-20000', (string) $entries[0]->getAmount());
    }

    /**
     * The tax given back is the tax that was collected, in the same
     * proportions — and it is negative for the same reason the amount is.
     */
    public function testARefundGivesBackTheTaxItCollected(): void
    {
        $creditNote = $this->issuedCreditNote(100_000, '20');

        $this->allocator()->allocate($creditNote, AllocationKind::Refund, 120_000);

        $entries = $this->entries();

        self::assertCount(1, $entries);
        self::assertSame('-100000', (string) $entries[0]->getNetAmount());
        self::assertSame('-20000', (string) $entries[0]->getTaxAmount());
    }

    /**
     * A payment reversed by the gateway used to leave its revenue standing:
     * recordInvoicePayment() found the entry it had already written and did
     * nothing, so the money went back and the books never heard.
     */
    public function testAPaymentTheGatewayReversedIsTakenBackOut(): void
    {
        $payment = $this->capturedPayment(120_000, new DateTimeImmutable('2026-02-10'));

        self::assertCount(1, $this->entries());

        $payment = $this->entityManager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(Payment::class, $payment);
        $payment->setStatus(PaymentStatus::Refunded);
        $this->entityManager->flush();

        $entries = $this->entries();

        self::assertCount(2, $entries);

        $reversal = $entries[1];
        self::assertSame(LedgerEntrySource::InvoiceRefund, $reversal->getSource());
        self::assertSame('-120000', (string) $reversal->getAmount());
        // Pointed at the entry it undoes, so the pair reads as one story.
        self::assertSame($entries[0]->getId(), $reversal->getReverses()?->getId());
    }

    /**
     * Nothing to take back if nothing was ever booked: a payment that failed
     * before capture and was then marked refunded produced no revenue.
     */
    public function testAReversalOfAnUnbookedPaymentWritesNothing(): void
    {
        $payment = $this->capturedPayment(120_000, new DateTimeImmutable('2026-02-10'), PaymentStatus::Pending);

        self::assertCount(0, $this->entries());

        $payment = $this->entityManager->find(Payment::class, $payment->getId());
        self::assertInstanceOf(Payment::class, $payment);
        $payment->setStatus(PaymentStatus::Refunded);
        $this->entityManager->flush();

        self::assertCount(0, $this->entries());
    }

    /**
     * The books are fed on every flush; they must not grow on flushes that
     * changed nothing.
     */
    public function testFlushingAgainDoesNotWriteASecondEntry(): void
    {
        $creditNote = $this->issuedCreditNote(50_000);

        $this->allocator()->allocate($creditNote, AllocationKind::Refund, 50_000);
        $this->entityManager->flush();
        $this->entityManager->flush();

        self::assertCount(1, $this->entries());
    }

    /**
     * The Urssaf imputes a refund to the period of the sale it corrects, not to
     * the period it was paid in: a sale in one quarter refunded in the next is
     * a correction of the first quarter, and once that quarter has been
     * declared it is that declaration which gets rectified.
     */
    public function testARefundIsDatedOnTheReceiptItTakesBack(): void
    {
        $payment = $this->capturedPayment(120_000, new DateTimeImmutable('2026-02-10'));
        $creditNote = $this->issuedCreditNoteFor($payment->getInvoice(), 50_000);

        $this->allocator()->allocate(
            $creditNote,
            AllocationKind::Refund,
            50_000,
            null,
            new DateTimeImmutable('2026-05-20'),
        );

        $refund = $this->refundEntry();

        self::assertSame(
            '2026-02-10',
            $refund->getEntryDate()->format('Y-m-d'),
            'The refund belongs to the quarter the money was taken in, not the one it went back in.',
        );
        self::assertSame(
            $payment->getId()?->toString(),
            $refund->getReverses()?->getSourceId()?->toString(),
            'The date and the reversal link come from the same receipt.',
        );
    }

    /**
     * An invoice paid in instalments has no single receipt to point at, and
     * picking one of them would attribute the refund to a period by guesswork.
     * It keeps the day it happened instead — the same condition the `reverses`
     * link already applies.
     */
    public function testARefundOnAnInvoicePaidInInstalmentsKeepsTheDayItHappened(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 60_000, new DateTimeImmutable('2026-02-10'));
        $this->payment($invoice, 60_000, new DateTimeImmutable('2026-03-11'));

        $creditNote = $this->issuedCreditNoteFor($invoice, 50_000);

        $this->allocator()->allocate(
            $creditNote,
            AllocationKind::Refund,
            50_000,
            null,
            new DateTimeImmutable('2026-05-20'),
        );

        $refund = $this->refundEntry();

        self::assertSame('2026-05-20', $refund->getEntryDate()->format('Y-m-d'));
        self::assertNull($refund->getReverses());
    }

    /**
     * Dating the entry in the past is safe even when that past is sealed: the
     * date stays truthful and the entry is filed into the earliest open period,
     * flagged late. That flag is the signal that a corrective declaration is
     * owed — which is exactly what the Urssaf asks for in this case.
     */
    public function testARefundOfASealedQuarterIsFiledLateAndKeepsItsDate(): void
    {
        $invoiceId = $this->capturedPayment(120_000, new DateTimeImmutable('2026-02-10'))
            ->getInvoice()
            ?->getId();

        $firstQuarter = $this->entries()[0]->getPeriod();
        self::assertInstanceOf(AccountingPeriod::class, $firstQuarter);
        self::getContainer()->get(AccountingPeriodManager::class)->close($firstQuarter);

        // A second quarter has to exist and be open for a late entry to have
        // somewhere to go.
        $this->capturedPayment(80_000, new DateTimeImmutable('2026-04-03'));

        // Re-read: entries() clears the identity map, which leaves the invoice
        // this test is holding detached.
        $invoice = $this->entityManager->find(Invoice::class, $invoiceId);
        $creditNote = $this->issuedCreditNoteFor($invoice, 20_000);

        $this->allocator()->allocate(
            $creditNote,
            AllocationKind::Refund,
            20_000,
            null,
            new DateTimeImmutable('2026-05-20'),
        );

        $refund = $this->refundEntry();

        self::assertSame(
            '2026-02-10',
            $refund->getEntryDate()->format('Y-m-d'),
            'The date stays truthful even though that quarter is sealed.',
        );
        self::assertTrue(
            $refund->isLateEntry(),
            'Filed into the open quarter and flagged — the signal that a corrective declaration is owed.',
        );
        self::assertNotSame(
            $firstQuarter->getId()?->toString(),
            $refund->getPeriod()?->getId()?->toString(),
            'A sealed period cannot take a new entry.',
        );
    }

    private function refundEntry(): LedgerEntry
    {
        foreach ($this->entries() as $entry) {
            if (LedgerEntrySource::InvoiceRefund === $entry->getSource()) {
                return $entry;
            }
        }

        self::fail('No refund entry was written.');
    }

    private function allocator(): CreditNoteAllocator
    {
        $allocator = self::getContainer()->get(CreditNoteAllocator::class);
        self::assertInstanceOf(CreditNoteAllocator::class, $allocator);

        return $allocator;
    }

    private function issuedCreditNote(int $amount, ?string $taxRate = null): CreditNote
    {
        $creditNote = new CreditNote();
        $creditNote->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $creditNote->setCreditNoteId('AV-' . uniqid());
        $creditNote->setReason(CreditReason::Cancellation);
        $creditNote->setCreditNoteDate(new DateTimeImmutable('2026-05-02'));
        $creditNote->setStatus(CreditNoteStatus::Issued);
        $creditNote->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $line = new CreditNoteLine()
            ->setDescription('Credited')
            ->setPrice($amount)
            ->setQty(1);

        if (null !== $taxRate) {
            $line->addTax($this->tax($taxRate));
        }

        $creditNote->addLine($line);

        $this->entityManager->persist($creditNote);
        $this->entityManager->flush();

        return $creditNote;
    }

    private function issuedCreditNoteFor(?Invoice $invoice, int $amount): CreditNote
    {
        $creditNote = $this->issuedCreditNote($amount);
        $creditNote->setCreditedInvoice($invoice);

        $this->entityManager->flush();

        return $creditNote;
    }

    private function payment(Invoice $invoice, int $amount, DateTimeImmutable $completed): Payment
    {
        $payment = new Payment();
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode('EUR');
        // Both sides, as Prepare.php does when a payment is recorded: the
        // feeder walks $invoice->getPayments() to find the receipt a refund
        // takes back, and an owning-side-only link leaves that collection empty.
        $invoice->addPayment($payment);
        $payment->setClient($invoice->getClient());
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted($completed);
        $payment->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function capturedPayment(
        int $amount,
        DateTimeImmutable $completed,
        PaymentStatus $status = PaymentStatus::Captured,
    ): Payment {
        $invoice = $this->invoice();

        $payment = new Payment();
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode('EUR');
        $invoice->addPayment($payment);
        $payment->setClient($invoice->getClient());
        $payment->setStatus($status);
        $payment->setCompleted($completed);
        $payment->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $payment;
    }

    private function invoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-01-05'));
        $invoice->addLine(
            new Line()
                ->setDescription('Consulting')
                ->setPrice(120_000)
                ->setQty(1),
        );

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function tax(string $rate): \Augias\TaxBundle\Entity\LineTax
    {
        return new \Augias\TaxBundle\Entity\LineTax()
            ->setNameSnapshot('VAT')
            ->setRateSnapshot($rate)
            ->setTypeSnapshot(\Augias\TaxBundle\Enum\TaxType::Exclusive)
            ->setCategorySnapshot(\Augias\TaxBundle\Enum\TaxCategory::Standard);
    }

    private function configureRegime(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);

        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
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
