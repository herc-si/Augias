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

namespace Augias\AccountingBundle\Service;

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\LedgerTaxSplit;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\BillBundle\Entity\BillPayment;
use Augias\CoreBundle\Entity\Company;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function count;
use function in_array;
use function trim;

/**
 * Writes the statutory books from what the rest of the application already
 * records, so that keeping them costs the user nothing.
 *
 * Cash accounting is what makes this possible: an entry is owed exactly when
 * money moves, and the application already knows when that happened — a
 * captured {@see Payment} on the revenue side, a {@see BillPayment} on the
 * purchase side. Nothing here reads invoices or bills themselves; an unpaid
 * invoice is not turnover under this regime and has no business in the book.
 *
 * Writing is idempotent. Every automatic entry carries the source record's id,
 * and one is written only if there is not one already — with a unique index
 * behind it as the backstop, since a retried payment webhook and a user
 * clicking twice both end up here.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\LedgerBookkeepingTest
 */
final readonly class LedgerFeeder
{
    /**
     * A payment counts as turnover once the money is actually in — authorised
     * is not captured, and pending is not money.
     *
     * A refund is deliberately not in this list and is not booked here at all.
     * One automatic entry exists per payment record, which is what the unique
     * index enforces and what makes re-flushing harmless; a refund is a second
     * movement of money against the same record, and the book takes it the way
     * accounting always has — as a reversing entry, entered by hand against the
     * original, so that both the receipt and its reversal stay visible.
     *
     * @var list<PaymentStatus>
     */
    private const array BOOKABLE_STATUSES = [PaymentStatus::Captured];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $registry,
        private AccountingPeriodManager $periodManager,
        private LedgerEntryRepository $entryRepository,
        private SystemConfig $systemConfig,
        private TranslatorInterface $translator,
        private LedgerTaxSplitter $taxSplitter,
    ) {
    }

    /**
     * Books a captured invoice payment into the revenue book.
     *
     * Returns null — without complaint — whenever there is nothing to book:
     * the payment is not captured, it settled out of the client's own credit,
     * the company keeps no books, or the entry already exists. This runs on
     * every payment written by the application, most of which belong to
     * companies that never enabled the module.
     */
    public function recordInvoicePayment(Payment $payment): ?LedgerEntry
    {
        $invoice = $payment->getInvoice();

        if (null === $invoice || ! in_array($payment->getStatus(), self::BOOKABLE_STATUSES, true)) {
            return null;
        }

        // Paying an invoice out of the client's credit balance is a captured
        // payment like any other — the invoice closes, and it must, because
        // that is how a credit note gets used up. But nothing was received.
        // Booking it would state a receipt that never happened, and under
        // cash-basis books that receipt is the taxable event: a €1,000 invoice
        // settled as €800 transferred plus €200 of credit would be declared as
        // €1,000 of turnover.
        if ($payment->getMethod()?->isClientCredit() === true) {
            return null;
        }

        $company = $invoice->getCompany();
        $profile = $this->books($company, LedgerBook::Revenue);

        if (! $profile instanceof AccountingProfile) {
            return null;
        }

        $id = $payment->getId();

        if (! $id instanceof Ulid) {
            return null;
        }

        $existing = $this->entryRepository
            ->findBySource($company, LedgerBook::Revenue, LedgerEntrySource::InvoicePayment, $id);

        if ($existing instanceof LedgerEntry) {
            return null;
        }

        $money = $payment->getAmount();
        $client = $payment->getClient() ?? $invoice->getClient();

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setSource(LedgerEntrySource::InvoicePayment)
            ->setSourceId($id)
            // The date the money moved, which for a captured payment is when it
            // completed — not when the invoice was raised, and not today.
            ->setEntryDate($payment->getCompleted() ?? new DateTimeImmutable('today'))
            ->setLabel($this->label('accounting.entry.label.invoice_payment', $company))
            ->setDocumentReference($invoice->getInvoiceId())
            ->setAmount(BigInteger::of($money->getAmount()))
            ->setCurrencyCode($money->getCurrency()->getCode())
            // The company's main activity is only a default: turnover of a
            // second kind has to be re-filed by hand, which is why the field
            // stays editable on an otherwise read-only automatic entry.
            ->setActivityNature($profile->primaryActivity)
            ->setSettlementMethod(SettlementMethod::fromGatewayName($payment->getMethod()?->getGatewayName()));

        $entry->setCompany($company);

        if (null !== $client) {
            $entry->setCounterparty($client);
        }

        if ('' === $entry->getCounterpartyName()) {
            $entry->setCounterpartyName((string) $invoice->getClient()?->getName());
        }

        // The tax contained in what was received, split by rate. Worked out
        // here because an entry is immutable once its period is sealed, and
        // because a single amount cannot be taken apart afterwards.
        $split = $this->taxSplitter->forInvoicePayment($invoice, BigInteger::of($money->getAmount()));

        if ($split instanceof LedgerTaxSplit) {
            $entry->setTax($split->net, $split->tax, $split->toArray());
        }

        return $this->persist($entry, $profile);
    }

    /**
     * Books money given back to a client against a credit note.
     *
     * Only a refund produces an entry. An offset needs none: under cash-basis
     * books the client simply pays less on the next invoice, and that smaller
     * receipt is already the whole truth — the revenue booked when the first
     * invoice was paid stays acquired, the next one is reduced by the same
     * amount, and the net is exact without anything being written here.
     *
     * The entry is dated on the day the money moved, which is what keeps a
     * refund out of a period that has already been sealed.
     *
     * @throws MathException
     */
    public function recordCreditNoteRefund(CreditNoteAllocation $allocation): ?LedgerEntry
    {
        if (! $allocation->getKind()->movesMoney()) {
            return null;
        }

        $creditNote = $allocation->getCreditNote();
        $company = $creditNote->getCompany();
        $profile = $this->books($company, LedgerBook::Revenue);

        if (! $profile instanceof AccountingProfile) {
            return null;
        }

        $id = $allocation->getId();

        if (! $id instanceof Ulid) {
            return null;
        }

        $existing = $this->entryRepository
            ->findBySource($company, LedgerBook::Revenue, LedgerEntrySource::InvoiceRefund, $id);

        if ($existing instanceof LedgerEntry) {
            return null;
        }

        $amount = BigInteger::of((string) $allocation->getAmount());
        $client = $creditNote->getClient();

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setSource(LedgerEntrySource::InvoiceRefund)
            ->setSourceId($id)
            ->setEntryDate($allocation->getAllocatedOn())
            ->setLabel($this->label('accounting.entry.label.invoice_refund', $company))
            ->setDocumentReference($creditNote->getCreditNoteId())
            // Negative: this is revenue going back out. Stored positive on the
            // document, signed here, which is where the books read it.
            ->setAmount($amount->negated())
            ->setCurrencyCode($client->getCurrency()->getCode())
            ->setActivityNature($profile->primaryActivity)
            ->setCounterparty($client)
            ->setReverses($this->soleEntryFor($creditNote));

        $entry->setCompany($company);

        if ('' === $entry->getCounterpartyName()) {
            $entry->setCounterpartyName((string) $client->getName());
        }

        // The tax given back, split by rate, in the proportions it was
        // collected in. Negated for the same reason as the amount.
        $split = $this->taxSplitter->forCreditNoteRefund($creditNote, $amount);

        if ($split instanceof LedgerTaxSplit) {
            $entry->setTax(
                BigInteger::of((string) $split->net)->negated(),
                BigInteger::of((string) $split->tax)->negated(),
                $split->toArray(),
            );
        }

        return $this->persist($entry, $profile);
    }

    /**
     * Books a payment the gateway reversed.
     *
     * This is not about credit notes at all. recordInvoicePayment() protects
     * itself from doubles by looking for an existing entry, which means a
     * payment that later flips to refunded finds one and does nothing — so the
     * revenue stayed acquired in the books although the money went back. This
     * is the entry that was missing.
     *
     * @throws MathException
     */
    public function recordPaymentRefund(Payment $payment): ?LedgerEntry
    {
        $invoice = $payment->getInvoice();

        if (null === $invoice || PaymentStatus::Refunded !== $payment->getStatus()) {
            return null;
        }

        $company = $invoice->getCompany();
        $profile = $this->books($company, LedgerBook::Revenue);

        if (! $profile instanceof AccountingProfile) {
            return null;
        }

        $id = $payment->getId();

        if (! $id instanceof Ulid) {
            return null;
        }

        $existing = $this->entryRepository
            ->findBySource($company, LedgerBook::Revenue, LedgerEntrySource::InvoiceRefund, $id);

        if ($existing instanceof LedgerEntry) {
            return null;
        }

        // Nothing to reverse if the payment was never booked — a payment that
        // failed before capture and was then marked refunded never produced
        // revenue to take back.
        $original = $this->entryRepository
            ->findBySource($company, LedgerBook::Revenue, LedgerEntrySource::InvoicePayment, $id);

        if (! $original instanceof LedgerEntry) {
            return null;
        }

        $money = $payment->getAmount();
        $client = $payment->getClient() ?? $invoice->getClient();

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setSource(LedgerEntrySource::InvoiceRefund)
            ->setSourceId($id)
            // The gateway does not tell us when it reversed, only that it did.
            // Today is when the books learned of it, and dating it any earlier
            // would be inventing a fact — possibly into a sealed period.
            ->setEntryDate(new DateTimeImmutable('today'))
            ->setLabel($this->label('accounting.entry.label.invoice_refund', $company))
            ->setDocumentReference($invoice->getInvoiceId())
            ->setAmount(BigInteger::of($money->getAmount())->negated())
            ->setCurrencyCode($money->getCurrency()->getCode())
            ->setActivityNature($original->getActivityNature() ?? $profile->primaryActivity)
            ->setSettlementMethod(SettlementMethod::fromGatewayName($payment->getMethod()?->getGatewayName()))
            ->setReverses($original);

        $entry->setCompany($company);

        if (null !== $client) {
            $entry->setCounterparty($client);
        }

        if ('' === $entry->getCounterpartyName()) {
            $entry->setCounterpartyName((string) $invoice->getClient()?->getName());
        }

        // Exactly what was booked, taken back: the split is already on the
        // entry being reversed, so there is nothing to recompute and no way for
        // the two to disagree.
        $net = $original->getNetAmount();
        $tax = $original->getTaxAmount();

        if (null !== $net && null !== $tax) {
            $entry->setTax(
                BigInteger::of((string) $net)->negated(),
                BigInteger::of((string) $tax)->negated(),
                $original->getTaxBreakdown() ?? [],
            );
        }

        return $this->persist($entry, $profile);
    }

    /**
     * The entry a credit note refund reverses, when there is exactly one
     * candidate.
     *
     * An invoice settled in several payments has several entries and no single
     * one is *the* original; guessing would put a misleading link in the books,
     * so the field stays empty and the document reference carries the story.
     */
    private function soleEntryFor(CreditNote $creditNote): ?LedgerEntry
    {
        $invoice = $creditNote->getCreditedInvoice();

        if (! $invoice instanceof Invoice) {
            return null;
        }

        $company = $creditNote->getCompany();
        $found = [];

        foreach ($invoice->getPayments() as $payment) {
            $paymentId = $payment->getId();

            if (! $paymentId instanceof Ulid) {
                continue;
            }

            $entry = $this->entryRepository
                ->findBySource($company, LedgerBook::Revenue, LedgerEntrySource::InvoicePayment, $paymentId);

            if ($entry instanceof LedgerEntry) {
                $found[] = $entry;
            }
        }

        return 1 === count($found) ? $found[0] : null;
    }

    /**
     * Books a supplier payment into the purchase register.
     *
     * Only for companies whose regime actually requires that register — a
     * French micro-entrepreneur on services alone is not obliged to keep one,
     * and filling a statutory register they do not have to produce would be
     * inventing an obligation.
     */
    public function recordBillPayment(BillPayment $payment): ?LedgerEntry
    {
        $bill = $payment->getBill();
        $company = $bill->getCompany();
        $profile = $this->books($company, LedgerBook::Purchase);

        if (! $profile instanceof AccountingProfile) {
            return null;
        }

        $id = $payment->getId();

        if (! $id instanceof Ulid) {
            return null;
        }

        $existing = $this->entryRepository
            ->findBySource($company, LedgerBook::Purchase, LedgerEntrySource::BillPayment, $id);

        if ($existing instanceof LedgerEntry) {
            return null;
        }

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Purchase)
            ->setSource(LedgerEntrySource::BillPayment)
            ->setSourceId($id)
            ->setEntryDate($payment->getPaidDate())
            ->setLabel($this->label('accounting.entry.label.bill_payment', $company))
            ->setDocumentReference($bill->getBillNumber())
            ->setAmount(BigInteger::of((string) $payment->getAmount()))
            ->setCurrencyCode($payment->getCurrencyCode())
            // Purchases carry no activity nature: nothing about them is capped
            // or charged per activity, and inventing one would only make the
            // register harder to read.
            ->setSettlementMethod(SettlementMethod::fromBillPaymentMethod($payment->getMethod()));

        $entry->setCompany($company)
            ->setCounterparty($bill->getSupplier());

        // The supplier's tax, in the share of the bill this payment settles.
        // No breakdown by rate: deductible VAT is declared as one figure, and
        // a supplier's bill is recorded as one too.
        $billTax = $bill->getTaxAmount();

        if ($billTax instanceof BigNumber) {
            $tax = $this->shareOf($billTax, $payment->getAmount(), $bill->getTotalAmount());
            $entry->setTax($entry->getAmount()->toBigInteger()->minus($tax), $tax, []);
        }

        return $this->persist($entry, $profile);
    }

    /**
     * The company's profile when it keeps the given book, null when it does
     * not — which covers both an unconfigured company and a regime that has no
     * such register.
     */
    private function books(Company $company, LedgerBook $book): ?AccountingProfile
    {
        $profile = $this->profileProvider->forCompany($company);
        $regime = $this->registry->forProfile($profile);

        if (null === $regime || ! in_array($book, $regime->books($profile), true)) {
            return null;
        }

        return $profile;
    }

    private function persist(LedgerEntry $entry, AccountingProfile $profile): LedgerEntry
    {
        $this->periodManager->assignPeriod($entry, $profile->declarationPeriodicity, $profile->fiscalYearStartMonth);

        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * Automatic labels are translated once, here, into the company's own
     * language and stored as plain text — the same treatment the seeded payment
     * methods get. A book is a document the user prints and keeps; resolving
     * its wording at render time would let a change of interface language
     * rewrite entries that were filed years ago.
     */
    private function label(string $key, Company $company): string
    {
        $locale = trim((string) $this->systemConfig->get(SystemConfig::LOCALE_CONFIG_PATH, $company));

        return $this->translator->trans($key, [], null, '' === $locale ? null : $locale);
    }

    /**
     * A payment's share of a figure on the document it settles.
     *
     * Cash accounting again: pay half a bill and half its tax is deductible,
     * not all of it. Paying more than was asked for deducts no more than the
     * supplier charged.
     *
     * @throws MathException
     */
    private function shareOf(BigNumber $amount, BigNumber $paid, BigNumber $total): BigInteger
    {
        $paid = BigDecimal::of($paid);
        $total = BigDecimal::of($total);

        if (! $total->isPositive() || $paid->isGreaterThanOrEqualTo($total)) {
            return BigDecimal::of($amount)->toScale(0, RoundingMode::HalfEven)->toBigInteger();
        }

        return BigDecimal::of($amount)
            ->multipliedBy($paid->dividedBy($total, 10, RoundingMode::HalfEven))
            ->toScale(0, RoundingMode::HalfEven)
            ->toBigInteger();
    }
}
