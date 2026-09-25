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
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\LedgerTaxSplit;
use Augias\AccountingBundle\Model\TaxShare;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Entity\BillPayment;
use Augias\BillBundle\Enum\BillStatus;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Company;
use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Enum\TaxCategory;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_map;
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

    /**
     * The places an invoice reaches only by having been issued. A cancelled
     * invoice is left out: under a regime that holds issued documents final
     * it cannot be cancelled, and elsewhere it was never a sale.
     *
     * @var list<InvoiceStatus>
     */
    private const array ISSUED_INVOICE_STATUSES = [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid];

    /**
     * A bill that stands: recorded and not withdrawn. A draft is not yet a
     * bill in hand; a cancelled one no longer is.
     *
     * @var list<BillStatus>
     */
    private const array RECEIVED_BILL_STATUSES = [BillStatus::Pending, BillStatus::Overdue, BillStatus::Paid];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountingProfileProvider $profileProvider,
        private AccountingPeriodManager $periodManager,
        private LedgerEntryRepository $entryRepository,
        private SystemConfig $systemConfig,
        private TranslatorInterface $translator,
        private LedgerTaxSplitter $taxSplitter,
        private CompanyBooks $companyBooks,
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
        $received = BigInteger::of($money->getAmount());
        $currency = $money->getCurrency()->getCode();
        // Money advanced for the client and invoiced back at cost came in, but
        // it is not turnover — so it is left out of the entry, and the label
        // says how much was, since the bank statement will show all of it.
        $disbursed = $this->disbursedIn($invoice, $received);

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setSource(LedgerEntrySource::InvoicePayment)
            ->setSourceId($id)
            // The date the money moved, which for a captured payment is when it
            // completed — not when the invoice was raised, and not today.
            ->setEntryDate($payment->getCompleted() ?? new DateTimeImmutable('today'))
            ->setLabel($this->label('accounting.entry.label.invoice_payment', $company, $disbursed, $currency))
            ->setDocumentReference($invoice->getInvoiceId())
            ->setAmount($received->minus($disbursed))
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
        $deposit = $this->isDeposit($invoice, $entry->getEntryDate());
        $split = $this->taxSplitter->forInvoicePayment($invoice, $received, $disbursed, $deposit);

        if ($split instanceof LedgerTaxSplit) {
            $entry->setTax($split->net, $split->tax, $split->toArray());
        }

        $entry = $this->persist($entry, $profile);

        if ($deposit && $split instanceof LedgerTaxSplit) {
            $this->recordDeposit($invoice, $id, $split, LedgerEntrySource::DepositReceived);
        }

        return $entry;
    }

    /**
     * Whether money received on this day came before the goods were
     * delivered — a deposit, whose tax on goods falls due on receipt rather
     * than on delivery (CGI art. 269, 2-a, second sentence).
     *
     * Only for goods: a service is taxed on receipt anyway, or on the invoice
     * under the option for debits, which comes first here since a payment
     * needs an issued invoice.
     */
    private function isDeposit(Invoice $invoice, DateTimeImmutable $received): bool
    {
        return $received->format('Y-m-d') < $invoice->getSupplyDate()->format('Y-m-d')
            && $this->hasGoods($invoice);
    }

    /**
     * Takes a deposit's goods out of the sales journal — or puts them back
     * when it is refunded.
     *
     * The invoice filed all its goods' tax there, for the delivery date. The
     * part a deposit paid for fell due earlier, on the payment, which already
     * declared it; left in the journal too, it would be declared twice. So
     * this writes the same shares against it, on the delivery date. Given
     * back, the deposit no longer paid for anything, and the goods fall due on
     * delivery again: the same entry the other way.
     *
     * Only against an invoice the journal holds. One filed before the books
     * were kept had nothing filed for its goods, and the deposit's receipt is
     * then the whole of the story.
     */
    private function recordDeposit(Invoice $invoice, Ulid $paymentId, LedgerTaxSplit $paid, LedgerEntrySource $source): void
    {
        $goods = $paid->goodsDueOnIssue();
        $invoiceId = $invoice->getId();
        $company = $invoice->getCompany();
        $client = $invoice->getClient();

        if (! $goods instanceof LedgerTaxSplit || ! $invoiceId instanceof Ulid || null === $client) {
            return;
        }

        $profile = $this->books($company, LedgerBook::Sales);

        if (! $profile instanceof AccountingProfile
            || ! $this->entryRepository->findBySource($company, LedgerBook::Sales, LedgerEntrySource::InvoiceIssued, $invoiceId) instanceof LedgerEntry
            || $this->entryRepository->findBySource($company, LedgerBook::Sales, $source, $paymentId) instanceof LedgerEntry) {
            return;
        }

        // Received: out of the journal. Refunded: back in, as it was.
        if ($source === LedgerEntrySource::DepositReceived) {
            $goods = $goods->negated();
        }

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Sales)
            ->setSource($source)
            ->setSourceId($paymentId)
            ->setEntryDate(DateTimeImmutable::createFromInterface($invoice->getSupplyDate()))
            ->setLabel($this->label('accounting.entry.label.' . $source->value, $company))
            ->setDocumentReference($invoice->getInvoiceId())
            ->setAmount($goods->net->plus($goods->tax))
            ->setCurrencyCode($client->getCurrency()->getCode())
            ->setActivityNature(ActivityNature::SaleOfGoods)
            ->setCounterparty($client)
            ->setTax($goods->net, $goods->tax, $goods->toArray());

        $entry->setCompany($company);

        if ('' === $entry->getCounterpartyName()) {
            $entry->setCounterpartyName((string) $client->getName());
        }

        $this->persist($entry, $profile);
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
     * The entry is dated on the receipt it takes back, not on the day the money
     * went out. The Urssaf imputes a refund to the period of the sale it
     * corrects: a €250 sale in March refunded €100 in April is €150 of March,
     * and once March has been declared it is March that gets a corrective
     * declaration — never April that gets a deduction. Dating the entry on the
     * refund moved the correction into a period the sale was never in, which
     * left both declarations wrong although the year came out right.
     *
     * Only when the receipt is unambiguous. An invoice paid in instalments has
     * no single original, and the refund then keeps the day it happened rather
     * than being attributed to a period by guesswork — the same condition
     * {@see self::soleEntryFor()} already applied to the `reverses` link, so
     * the date and the link now come from one answer instead of two.
     *
     * A date inside a sealed period needs no avoiding here:
     * {@see AccountingPeriodManager::assignPeriod()} keeps the date truthful
     * and files the entry into the earliest period still open, flagged late.
     * That flag is precisely the case where the Urssaf expects a corrective
     * declaration, so it is worth seeing rather than worth hiding.
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
        $currency = $client->getCurrency()->getCode();
        $reversed = $this->soleEntryFor($creditNote);
        // A disbursement given back was never revenue, so it is not taken out
        // of revenue either — the mirror of what a payment does.
        $disbursed = $this->disbursedIn($creditNote, $amount);

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setSource(LedgerEntrySource::InvoiceRefund)
            ->setSourceId($id)
            ->setEntryDate($reversed?->getEntryDate() ?? $allocation->getAllocatedOn())
            ->setLabel($this->label('accounting.entry.label.invoice_refund', $company, $disbursed, $currency))
            ->setDocumentReference($creditNote->getCreditNoteId())
            // Negative: this is revenue going back out. Stored positive on the
            // document, signed here, which is where the books read it.
            ->setAmount($amount->minus($disbursed)->negated())
            ->setCurrencyCode($currency)
            ->setActivityNature($profile->primaryActivity)
            ->setCounterparty($client)
            ->setReverses($reversed);

        $entry->setCompany($company);

        if ('' === $entry->getCounterpartyName()) {
            $entry->setCounterpartyName((string) $client->getName());
        }

        // The tax given back, split by rate, in the proportions it was
        // collected in. Negated for the same reason as the amount.
        $split = $this->taxSplitter->forCreditNoteRefund($creditNote, $amount, $disbursed);

        if ($split instanceof LedgerTaxSplit) {
            $split = $split->negated();
            $entry->setTax($split->net, $split->tax, $split->toArray());
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
        $currency = $money->getCurrency()->getCode();
        // What was booked, not what went back: the part of the payment that
        // settled disbursements never entered the book, so it has nothing to
        // leave it. Read off the original rather than worked out again, so the
        // pair cancels exactly even if the invoice has changed since.
        $booked = $original->getAmount()->toBigInteger();
        $disbursed = BigInteger::of($money->getAmount())->minus($booked);

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setSource(LedgerEntrySource::InvoiceRefund)
            ->setSourceId($id)
            // The gateway does not tell us when it reversed, only that it did.
            // Today is when the books learned of it, and dating it any earlier
            // would be inventing a fact — possibly into a sealed period.
            ->setEntryDate(new DateTimeImmutable('today'))
            ->setLabel($this->label('accounting.entry.label.invoice_refund', $company, $disbursed, $currency))
            ->setDocumentReference($invoice->getInvoiceId())
            ->setAmount($booked->negated())
            ->setCurrencyCode($currency)
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

        // A deposit given back: its goods fall due on delivery again, so the
        // sales journal takes back the entry that had set them aside.
        $setAside = $this->entryRepository->findBySource($company, LedgerBook::Sales, LedgerEntrySource::DepositReceived, $id);

        if ($setAside instanceof LedgerEntry && null !== $setAside->getNetAmount() && null !== $setAside->getTaxAmount()) {
            $this->recordDeposit(
                $invoice,
                $id,
                new LedgerTaxSplit(
                    $setAside->getNetAmount()->toBigInteger()->negated(),
                    $setAside->getTaxAmount()->toBigInteger()->negated(),
                    array_map(
                        static fn (array $share): TaxShare => new TaxShare(
                            $share['rate'],
                            TaxCategory::from($share['category']),
                            BigInteger::of($share['base'])->negated(),
                            BigInteger::of($share['tax'])->negated(),
                            true,
                            true,
                        ),
                        $setAside->getTaxBreakdown() ?? [],
                    ),
                ),
                LedgerEntrySource::DepositRefunded,
            );
        }

        if (null !== $net && null !== $tax) {
            $entry->setTax(
                BigInteger::of((string) $net)->negated(),
                BigInteger::of((string) $tax)->negated(),
                array_map(
                    static function (array $share): array {
                        $share['base'] = (string) BigInteger::of($share['base'])->negated();
                        $share['tax'] = (string) BigInteger::of($share['tax'])->negated();

                        return $share;
                    },
                    $original->getTaxBreakdown() ?? [],
                ),
            );
        }

        return $this->persist($entry, $profile);
    }

    /**
     * Files the VAT that fell due when an invoice was issued into the sales
     * journal: its goods', and its services' too under the option for VAT on
     * debits (art. 269, 2-c).
     *
     * Goods are taxed on delivery, not on payment (CGI art. 269, 2-a), and the
     * invoice date stands in for the delivery: an invoice for goods is issued
     * when they are delivered. So the entry is dated on the invoice, and the
     * return for that period declares the tax whether the client has paid or
     * not. When they do, the revenue book records the receipt with the same
     * shares marked as already declared.
     *
     * Returns null when there is nothing to file: the invoice is not issued,
     * carries no goods or no tax, the company charges no VAT or keeps no
     * books, or the entry is already there. It runs on every invoice flushed.
     *
     * One limit, stated rather than guessed around: a deposit paid before the
     * invoice makes the tax due on the day it is received (art. 269, 2-a,
     * second sentence). Augias takes payments against issued invoices only,
     * so that day cannot come first here.
     *
     * @throws MathException
     */
    public function recordInvoiceIssue(Invoice $invoice): ?LedgerEntry
    {
        if (! in_array($invoice->getStatus(), self::ISSUED_INVOICE_STATUSES, true) || ! $this->hasTaxDueOnIssue($invoice)) {
            return null;
        }

        $client = $invoice->getClient();

        if (null === $client) {
            return null;
        }

        return $this->recordIssue(
            $invoice,
            $invoice->getCompany(),
            LedgerEntrySource::InvoiceIssued,
            // On delivery, which the invoice date stands in for when no other
            // date was given.
            DateTimeImmutable::createFromInterface($invoice->getSupplyDate()),
            $invoice->getInvoiceId(),
            $client,
            'accounting.entry.label.invoice_issued',
            false,
        );
    }

    /**
     * Takes back, in the sales journal, the VAT on the goods a credit note
     * credits — on the day the credit note is issued, whatever happens to the
     * money afterwards (CGI art. 272, 1). The refund or offset that follows
     * moves money; it no longer moves this tax.
     *
     * @throws MathException
     */
    public function recordCreditNoteIssue(CreditNote $creditNote): ?LedgerEntry
    {
        if (! $creditNote->isIssued() || ! $this->hasTaxDueOnIssue($creditNote)) {
            return null;
        }

        return $this->recordIssue(
            $creditNote,
            $creditNote->getCompany(),
            LedgerEntrySource::CreditNoteIssued,
            $creditNote->getCreditNoteDate(),
            $creditNote->getCreditNoteId(),
            $creditNote->getClient(),
            'accounting.entry.label.credit_note_issued',
            true,
        );
    }

    /**
     * @throws MathException
     */
    private function recordIssue(
        Invoice | CreditNote $document,
        Company $company,
        LedgerEntrySource $source,
        DateTimeImmutable $date,
        string $reference,
        Client $client,
        string $label,
        bool $negate,
    ): ?LedgerEntry {
        $id = $document->getId();

        if (! $id instanceof Ulid) {
            return null;
        }

        $profile = $this->books($company, LedgerBook::Sales);

        if (! $profile instanceof AccountingProfile) {
            return null;
        }

        if ($this->entryRepository->findBySource($company, LedgerBook::Sales, $source, $id) instanceof LedgerEntry) {
            return null;
        }

        $split = $this->taxSplitter->forIssue($document);

        if (! $split instanceof LedgerTaxSplit) {
            return null;
        }

        if ($negate) {
            $split = $split->negated();
        }

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Sales)
            ->setSource($source)
            ->setSourceId($id)
            ->setEntryDate($date)
            ->setLabel($this->label($label, $company))
            ->setDocumentReference($reference)
            // What the goods were invoiced at, tax included: the figure the
            // client owes for them, as a sales journal records it.
            ->setAmount($split->net->plus($split->tax))
            ->setCurrencyCode($client->getCurrency()->getCode())
            ->setActivityNature(ActivityNature::SaleOfGoods)
            ->setCounterparty($client)
            ->setTax($split->net, $split->tax, $split->toArray());

        $entry->setCompany($company);

        if ('' === $entry->getCounterpartyName()) {
            $entry->setCounterpartyName((string) $client->getName());
        }

        return $this->persist($entry, $profile);
    }

    /**
     * Whether any of the document's tax falls due on issue: all of it under
     * the option for VAT on debits, the goods' otherwise. Checked before
     * anything is loaded, since this runs on every invoice flushed and most
     * carry nothing of the kind.
     */
    private function hasTaxDueOnIssue(Invoice | CreditNote $document): bool
    {
        return $document->isVatOnDebits() || $this->hasGoods($document);
    }

    /**
     * Whether any line sells goods. Disbursements sell nothing.
     */
    private function hasGoods(Invoice | CreditNote $document): bool
    {
        foreach ($document->getLines() as $line) {
            if ($line->getSupplyType()->isTaxedOnIssue() && ! $line->isDisbursement()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Files a supplier's bill into the purchase journal when its VAT is
     * deductible on its date — goods, or services from a supplier on debits
     * (CGI art. 271, I-2).
     *
     * Dated on the bill: that is when the tax fell due at the supplier. The
     * right to deduct also needs the bill in hand, which a bill recorded here
     * is. One figure, as the purchase register records it.
     *
     * @throws MathException
     */
    public function recordBillReceipt(Bill $bill): ?LedgerEntry
    {
        if (! $bill->getTaxAmount() instanceof BigNumber || ! $this->deductedOnReceipt($bill)) {
            return null;
        }

        $id = $bill->getId();
        $company = $bill->getCompany();

        if (! $id instanceof Ulid) {
            return null;
        }

        $profile = $this->books($company, LedgerBook::Bills);

        if (! $profile instanceof AccountingProfile
            || $this->entryRepository->findBySource($company, LedgerBook::Bills, LedgerEntrySource::BillReceived, $id) instanceof LedgerEntry) {
            return null;
        }

        $tax = $bill->getTaxAmount()->toBigInteger();
        $total = $bill->getTotalAmount()->toBigInteger();

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Bills)
            ->setSource(LedgerEntrySource::BillReceived)
            ->setSourceId($id)
            ->setEntryDate($bill->getIssueDate() ?? new DateTimeImmutable('today'))
            ->setLabel($this->label('accounting.entry.label.bill_received', $company))
            ->setDocumentReference($bill->getBillNumber())
            ->setAmount($total)
            ->setCurrencyCode($bill->getCurrencyCode())
            ->setTax($total->minus($tax), $tax, []);

        $entry->setCompany($company)
            ->setCounterparty($bill->getSupplier());

        return $this->persist($entry, $profile);
    }

    /**
     * Takes the deduction back when a bill it was made on is cancelled. Dated
     * today: that is when the books learned the bill no longer stands.
     *
     * @throws MathException
     */
    public function recordBillCancellation(Bill $bill): ?LedgerEntry
    {
        $id = $bill->getId();

        if (BillStatus::Cancelled !== $bill->getStatus() || ! $id instanceof Ulid) {
            return null;
        }

        $company = $bill->getCompany();
        $profile = $this->books($company, LedgerBook::Bills);

        if (! $profile instanceof AccountingProfile) {
            return null;
        }

        $received = $this->entryRepository->findBySource($company, LedgerBook::Bills, LedgerEntrySource::BillReceived, $id);

        if (! $received instanceof LedgerEntry
            || $this->entryRepository->findBySource($company, LedgerBook::Bills, LedgerEntrySource::BillCancelled, $id) instanceof LedgerEntry) {
            return null;
        }

        $net = $received->getNetAmount();
        $tax = $received->getTaxAmount();

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Bills)
            ->setSource(LedgerEntrySource::BillCancelled)
            ->setSourceId($id)
            ->setEntryDate(new DateTimeImmutable('today'))
            ->setLabel($this->label('accounting.entry.label.bill_cancelled', $company))
            ->setDocumentReference($bill->getBillNumber())
            ->setAmount($received->getAmount()->toBigInteger()->negated())
            ->setCurrencyCode($received->getCurrencyCode())
            ->setReverses($received);

        if (null !== $net && null !== $tax) {
            $entry->setTax($net->toBigInteger()->negated(), $tax->toBigInteger()->negated(), []);
        }

        $entry->setCompany($company)
            ->setCounterparty($bill->getSupplier());

        return $this->persist($entry, $profile);
    }

    /**
     * Whether this bill's VAT is — or is to be — deducted from the purchase
     * journal on its date rather than from each payment.
     *
     * Settled by what the books already hold, not only by what the bill says
     * now, because a bill can be edited after the fact: once deducted on
     * receipt it stays so, and a bill whose payments were already deducted
     * one by one is not deducted again on receipt — either way round, the
     * tax is deducted once.
     */
    private function deductedOnReceipt(Bill $bill): bool
    {
        $id = $bill->getId();
        $company = $bill->getCompany();

        if ($id instanceof Ulid
            && $this->entryRepository->findBySource($company, LedgerBook::Bills, LedgerEntrySource::BillReceived, $id) instanceof LedgerEntry) {
            return true;
        }

        // A draft is not a bill in hand, and its receipt is not recorded: its
        // payments deduct their own share, as they always have.
        if (! $bill->isTaxDeductibleOnIssue() || ! in_array($bill->getStatus(), self::RECEIVED_BILL_STATUSES, true)) {
            return false;
        }

        // The stored payments as well as the ones in hand: a payment recorded
        // without being added to the bill's collection is still a payment.
        $payments = [...$bill->getPayments(), ...$this->entityManager->getRepository(BillPayment::class)->findBy(['bill' => $bill])];

        foreach ($payments as $payment) {
            $paymentId = $payment->getId();

            if (! $paymentId instanceof Ulid) {
                continue;
            }

            $entry = $this->entryRepository->findBySource($company, LedgerBook::Purchase, LedgerEntrySource::BillPayment, $paymentId);

            if ($entry instanceof LedgerEntry && $entry->deductedTax()?->isPositive() === true) {
                return false;
            }
        }

        return true;
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
            $net = $entry->getAmount()->toBigInteger()->minus($tax);

            // Deducted already, from the purchase journal on the bill's date:
            // recorded here because the money did contain it, marked so that
            // the return does not deduct it a second time. One share, with no
            // rate — a supplier's bill is recorded as one figure.
            $shares = $this->deductedOnReceipt($bill)
                ? [['rate' => '', 'category' => '', 'base' => (string) $net, 'tax' => (string) $tax, 'due' => TaxShare::DUE_ON_ISSUE]]
                : [];

            $entry->setTax($net, $tax, $shares);
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

        if (! in_array($book, $this->companyBooks->statutory($profile), true)) {
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
    private function label(string $key, Company $company, ?BigInteger $disbursed = null, string $currency = ''): string
    {
        $locale = trim((string) $this->systemConfig->get(SystemConfig::LOCALE_CONFIG_PATH, $company));
        $locale = '' === $locale ? null : $locale;

        if (! $disbursed instanceof BigInteger || ! $disbursed->isPositive()) {
            return $this->translator->trans($key, [], null, $locale);
        }

        // The part left out is named on the entry itself, in figures: the
        // bank statement shows the whole receipt, and whoever reconciles the
        // two has to see where the difference went without opening the
        // invoice.
        $formatter = new IntlMoneyFormatter(
            new NumberFormatter($locale ?? $this->translator->getLocale(), NumberFormatter::CURRENCY),
            new ISOCurrencies(),
        );

        return $this->translator->trans($key . '_with_disbursement', [
            '%disbursed%' => $formatter->format(new Money((string) $disbursed, new Currency($currency))),
        ], null, $locale);
    }

    /**
     * The part of a settlement that went to the document's disbursements.
     *
     * Pro rata of the total, the convention the tax already follows on a
     * partial payment: a client who pays half an invoice has paid half of each
     * thing on it. Settling the disbursements first would favour the company's
     * figures with nothing in the law to justify it. A payment of the whole
     * amount or more covers all of them, and an overpayment is turnover like
     * any other — it was not advanced for anyone.
     *
     * @throws MathException
     */
    private function disbursedIn(BaseInvoice $document, BigInteger $settled): BigInteger
    {
        $disbursements = $document->getDisbursementTotal();

        if (! $disbursements->isPositive()) {
            return BigInteger::zero();
        }

        // What the client was asked for, as the tax split reads it: the
        // payable amount once withholding is off, since that is what they pay.
        $payable = $document->getPayableAmount();
        $total = $payable->isPositive() ? $payable : $document->getTotal();

        return $this->shareOf($disbursements, $settled, $total);
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
