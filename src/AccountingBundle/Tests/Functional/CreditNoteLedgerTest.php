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
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
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
        // The day the money moved, which is what keeps a refund out of a period
        // that has already been sealed.
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

    private function capturedPayment(
        int $amount,
        DateTimeImmutable $completed,
        PaymentStatus $status = PaymentStatus::Captured,
    ): Payment {
        $invoice = $this->invoice();

        $payment = new Payment();
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode('EUR');
        $payment->setInvoice($invoice);
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
