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

namespace Augias\AccountingBundle\Mcp;

use Augias\AccountingBundle\Bank\Reconciler;
use Augias\AccountingBundle\Bank\ReconciliationRefused;
use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\DeclarationKind;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Exception\LedgerLockedException;
use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Repository\AccountingPeriodRepository;
use Augias\AccountingBundle\Repository\BankTransactionRepository;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CompanyBooks;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\AccountingBundle\Service\DeclarationBuilder;
use Augias\AccountingBundle\Service\LedgerLockDate;
use Augias\AccountingBundle\Service\LedgerTaxSplitter;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Augias\TaxBundle\Entity\Tax;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyPermission;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writing in the books through MCP, under the rules of the screens: a hand
 * entry only in a book kept by hand and not before the lock date, a period
 * closed only once it has ended, a declaration marked filed once, a bank line
 * matched against a record that fits it.
 *
 * Each tool needs both mcp:finance and mcp:write, and the role in the company
 * that may write in the books (bank lines: the role that may change documents).
 */
final readonly class AccountingWriteTools
{
    public function __construct(
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $regimes,
        private CompanyBooks $books,
        private CurrentCompany $currentCompany,
        private AccountingPeriodManager $periodManager,
        private AccountingPeriodRepository $periodRepository,
        private LedgerEntryRepository $entryRepository,
        private LedgerLockDate $lockDate,
        private LedgerTaxSplitter $taxSplitter,
        private DeclarationBuilder $declarationBuilder,
        private BankTransactionRepository $bankTransactionRepository,
        private Reconciler $reconciler,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
        private Security $security,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * Write an entry by hand: a receipt the application did not record
     * (revenue), or an expense or a purchase paid outside Augias.
     *
     * @param string      $book               Book written to: revenue, purchase or expense (the sales and bills journals are written by the application)
     * @param string      $entry_date         ISO-8601 date the money moved
     * @param string      $label              What it is
     * @param string      $counterparty       Who paid or was paid
     * @param int|string  $amount             Amount including tax, in the minor unit (e.g. cents)
     * @param string      $settlement_method  One of: bank_transfer, check, credit_card, direct_debit, cash, online, other
     * @param string|null $document_reference Invoice or receipt number; required for a purchase claiming VAT
     * @param string|null $activity_nature    For a receipt: sale_of_goods, services_bic or services_bnc (defaults to the main activity)
     * @param string|null $tax_id             Tax rate included in the amount (not for a company in VAT franchise)
     * @param string|null $notes              Notes
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'add_ledger_entry', description: 'Write an entry by hand in the revenue, purchase or expense book (amount incl. tax in the minor unit, date, label, counterparty, settlement method, optional tax and document reference). Needs mcp:finance and mcp:write.')]
    #[McpScopeRequired(McpScope::Finance)]
    #[McpScopeRequired(McpScope::Write)]
    public function addLedgerEntry(
        string $book,
        string $entry_date,
        string $label,
        string $counterparty,
        int | string $amount,
        string $settlement_method = 'bank_transfer',
        ?string $document_reference = null,
        ?string $activity_nature = null,
        ?string $tax_id = null,
        ?string $notes = null,
    ): array {
        $this->requireBooksWrite();

        $profile = $this->profileProvider->forCompany();
        $ledgerBook = LedgerBook::tryFrom($book);

        if (! $this->books->keeps($profile, $ledgerBook)) {
            throw new ToolCallException(sprintf('The company does not keep a "%s" book. Books kept: %s.', $book, implode(', ', array_map(static fn (LedgerBook $kept): string => $kept->value, $this->books->all($profile)))));
        }

        \assert($ledgerBook instanceof LedgerBook);

        if (! $ledgerBook->acceptsManualEntries()) {
            throw new ToolCallException(sprintf('The "%s" book is written by the application alone.', $book));
        }

        try {
            $value = BigInteger::of($amount);
        } catch (MathException) {
            throw new ToolCallException('amount must be a whole number in the minor unit (e.g. cents).');
        }

        $entry = new LedgerEntry()
            ->setBook($ledgerBook)
            ->setSource(LedgerEntrySource::Manual)
            ->setCurrencyCode($profile->currencyCode)
            ->setEntryDate($this->date($entry_date))
            ->setLabel(trim($label))
            ->setCounterpartyName(trim($counterparty))
            ->setAmount($value)
            ->setDocumentReference($document_reference === null || trim($document_reference) === '' ? null : trim($document_reference))
            ->setSettlementMethod(SettlementMethod::tryFrom($settlement_method) ?? throw new ToolCallException(sprintf(
                'Invalid settlement_method. Valid values: %s.',
                implode(', ', array_map(static fn (SettlementMethod $case): string => $case->value, SettlementMethod::cases())),
            )))
            ->setNotes($notes);

        if ($ledgerBook === LedgerBook::Revenue) {
            $entry->setActivityNature($activity_nature === null ? $profile->primaryActivity : (ActivityNature::tryFrom($activity_nature) ?? throw new ToolCallException('activity_nature must be sale_of_goods, services_bic or services_bnc.')));
        }

        if ($tax_id !== null && $tax_id !== '') {
            if ($profile->vatExempt) {
                throw new ToolCallException('The company is in VAT franchise: an entry records no VAT.');
            }

            $tax = $this->entityManager->getRepository(Tax::class)->find(UlidParser::parse($tax_id, 'tax_id'));

            if (! $tax instanceof Tax || $tax->getType() === Tax::TYPE_FLAT_RATE) {
                throw new ToolCallException(sprintf('Tax %s not found, or a flat-rate tax (only a percentage can be taken out of an amount).', $tax_id));
            }

            // Deducting VAT needs the invoice that carries it (CGI, art. 271-II).
            if ($ledgerBook === LedgerBook::Purchase && $entry->getDocumentReference() === null) {
                throw new ToolCallException('A purchase claiming VAT needs the document_reference of the invoice that carries it.');
            }

            $split = $this->taxSplitter->forManualEntry($entry->getAmount(), $tax);
            $entry->setTax($split->net, $split->tax, $split->toArray());
        }

        $entry->setCompany($this->currentCompany->require());

        if ($this->lockDate->shuts($entry)) {
            throw new ToolCallException(sprintf('The books are locked up to %s: no entry can be written on or before it.', $this->lockDate->forCompany($entry->getCompany())?->format('Y-m-d') ?? 'the lock date'));
        }

        $errors = $this->validator->validate($entry);

        if (\count($errors) > 0) {
            $messages = [];

            foreach ($errors as $error) {
                $messages[] = sprintf('%s: %s', $error->getPropertyPath(), $error->getMessage());
            }

            throw new ToolCallException(sprintf('Validation failed: %s', implode('; ', $messages)));
        }

        $this->periodManager->assignPeriod($entry, $profile->declarationPeriodicity, $profile->fiscalYearStartMonth);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return ['id' => $entry->getId()?->toRfc4122(), 'book' => $ledgerBook->value, 'date' => $entry->getEntryDate()->format('Y-m-d'), 'amount' => (string) $entry->getAmount(), 'tax_amount' => $entry->getTaxAmount()?->__toString()];
    }

    /**
     * Delete an entry written by hand, if the books are not locked over it.
     * Entries the application wrote follow their documents and are not deleted.
     *
     * @param string $entry_id Entry ULID (from list_ledger_entries)
     *
     * @return array{deleted: bool, entry_id: string}
     */
    #[McpTool(name: 'delete_ledger_entry', description: 'Delete an entry written by hand, unless the books are locked over it. Needs mcp:finance and mcp:write.')]
    #[McpScopeRequired(McpScope::Finance)]
    #[McpScopeRequired(McpScope::Write)]
    public function deleteLedgerEntry(string $entry_id): array
    {
        $this->requireBooksWrite();

        $entry = $this->entryRepository->find(UlidParser::parse($entry_id, 'entry_id'));

        if (! $entry instanceof LedgerEntry) {
            throw new ToolCallException(sprintf('Entry %s not found.', $entry_id));
        }

        if ($entry->isLocked() || $this->lockDate->shuts($entry)) {
            throw new ToolCallException('The books are locked over this entry: it cannot be deleted.');
        }

        if ($entry->getSource() !== LedgerEntrySource::Manual) {
            throw new ToolCallException('This entry was written by the application from a document; change or cancel the document instead.');
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        return ['deleted' => true, 'entry_id' => $entry_id];
    }

    /**
     * Close a declaration period once it has ended: its figures are sealed and
     * the declaration can be filed.
     *
     * @param string $period_id Period ULID (from get_accounting_overview or list_pending_declarations)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'close_accounting_period', description: 'Close (seal) a declaration period that has ended, so its declaration can be filed. Irreversible. Needs mcp:finance and mcp:write.')]
    #[McpScopeRequired(McpScope::Finance)]
    #[McpScopeRequired(McpScope::Write)]
    public function closeAccountingPeriod(string $period_id): array
    {
        $this->requireBooksWrite();

        $period = $this->period($period_id);
        $user = $this->security->getUser();

        try {
            $this->periodManager->close($period, $user instanceof User ? $user : null);
        } catch (LedgerLockedException $exception) {
            throw new ToolCallException($exception->getMessage(), 0, $exception);
        }

        return $this->periodRow($period);
    }

    /**
     * Create the declaration period that holds a date, when it does not
     * exist yet (a quarter with nothing in it still has to be declared).
     *
     * @param string      $date ISO-8601 date inside the period
     * @param string|null $type month, quarter or year; defaults to the declaration periodicity
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_accounting_period', description: 'Create the declaration period holding a date (e.g. a quarter with no receipts that still has to be declared). Needs mcp:finance and mcp:write.')]
    #[McpScopeRequired(McpScope::Finance)]
    #[McpScopeRequired(McpScope::Write)]
    public function createAccountingPeriod(string $date, ?string $type = null): array
    {
        $this->requireBooksWrite();

        $profile = $this->profileProvider->forCompany();

        if (! $this->regimes->forProfile($profile) instanceof RegimeInterface) {
            throw new ToolCallException('No accounting regime is set up for this company.');
        }

        $periodType = $type === null ? $profile->declarationPeriodicity : (PeriodType::tryFrom($type) ?? throw new ToolCallException('type must be month, quarter or year.'));

        $period = $this->periodManager->periodFor($this->currentCompany->require(), $periodType, $this->date($date), $profile->fiscalYearStartMonth);
        $this->entityManager->flush();

        return $this->periodRow($period);
    }

    /**
     * Record that a declaration was filed with the administration, with its
     * reference. Filing itself is done on the administration's site.
     *
     * @param string      $period_id Period ULID
     * @param string      $kind      social_contributions or vat
     * @param string|null $reference Filing reference given by the administration
     * @param string|null $notes     Notes
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'mark_declaration_filed', description: 'Record that a declaration (kind social_contributions or vat) of a period was filed with the administration, with its reference. Needs mcp:finance and mcp:write.')]
    #[McpScopeRequired(McpScope::Finance)]
    #[McpScopeRequired(McpScope::Write)]
    public function markDeclarationFiled(string $period_id, string $kind, ?string $reference = null, ?string $notes = null): array
    {
        $this->requireBooksWrite();

        $period = $this->period($period_id);
        $declarationKind = DeclarationKind::tryFrom($kind) ?? throw new ToolCallException('kind must be social_contributions or vat.');
        $declaration = $this->declarationBuilder->forPeriod($period, $declarationKind);

        if ($declaration->isSubmitted()) {
            throw new ToolCallException('This declaration is already recorded as filed.');
        }

        $this->declarationBuilder->markSubmitted(
            $declaration,
            $reference === null || trim($reference) === '' ? null : trim($reference),
            $notes === null || trim($notes) === '' ? null : trim($notes),
        );

        return [
            'period' => $this->periodRow($period),
            'kind' => $declarationKind->value,
            'submitted_at' => $declaration->getSubmittedAt()?->format('Y-m-d'),
            'reference' => $declaration->getReference(),
            'total_due' => (string) $declaration->getTotalDue(),
        ];
    }

    /**
     * Reconcile a bank statement line: match it with a payment, an invoice,
     * a purchase payment or a purchase (see the suggestions of
     * list_bank_transactions), set it aside, or reopen it.
     *
     * @param string      $bank_transaction_id Statement line ULID
     * @param string      $action              match, ignore or reopen
     * @param string|null $kind                For match: payment, invoice, bill_payment or bill
     * @param string|null $target_id           For match: the ULID of that record
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'reconcile_bank_transaction', description: 'Reconcile a bank statement line: action "match" with kind (payment, invoice, bill_payment, bill) and target_id from the suggestions of list_bank_transactions, "ignore", or "reopen". Needs mcp:finance and mcp:write.')]
    #[McpScopeRequired(McpScope::Finance)]
    #[McpScopeRequired(McpScope::Write)]
    public function reconcileBankTransaction(string $bank_transaction_id, string $action, ?string $kind = null, ?string $target_id = null): array
    {
        $this->scopeGuard->require(McpScope::Finance);
        $this->scopeGuard->require(McpScope::Write);

        $line = $this->bankTransactionRepository->find(UlidParser::parse($bank_transaction_id, 'bank_transaction_id'));

        if (! $line instanceof BankTransaction) {
            throw new ToolCallException(sprintf('Bank line %s not found.', $bank_transaction_id));
        }

        try {
            match ($action) {
                'match' => $this->reconciler->match(
                    $line,
                    $kind ?? throw new ToolCallException('kind is required to match a line.'),
                    $target_id ?? throw new ToolCallException('target_id is required to match a line.'),
                ),
                'ignore' => $this->reconciler->ignore($line),
                'reopen' => $this->reconciler->reopen($line),
                default => throw new ToolCallException('action must be match, ignore or reopen.'),
            };
        } catch (ReconciliationRefused $refused) {
            throw new ToolCallException($refused->trans($this->translator, 'en'), 0, $refused);
        }

        return [
            'id' => $bank_transaction_id,
            'status' => $line->getStatus()->value,
            'matched_payment_id' => $line->getPayment()?->getId()?->toRfc4122(),
            'matched_bill_payment_id' => $line->getBillPayment()?->getId()?->toRfc4122(),
        ];
    }

    /**
     * Both scopes, and the role that may write in the books: an accountant's
     * assistant reads, whatever it was granted.
     */
    private function requireBooksWrite(): void
    {
        $this->scopeGuard->require(McpScope::Finance);
        $this->scopeGuard->require(McpScope::Write);

        if (! $this->security->isGranted(CompanyPermission::AccountingWrite->value)) {
            throw new ToolCallException('Your role in this company does not allow writing in the books.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function periodRow(AccountingPeriod $period): array
    {
        return [
            'id' => $period->getId()?->toRfc4122(),
            'label' => $period->getLabel(),
            'start' => $period->getStartDate()->format('Y-m-d'),
            'end' => $period->getEndDate()->format('Y-m-d'),
            'status' => $period->getStatus()->value,
        ];
    }

    private function period(string $periodId): AccountingPeriod
    {
        $period = $this->periodRepository->find(UlidParser::parse($periodId, 'period_id'));

        if (! $period instanceof AccountingPeriod) {
            throw new ToolCallException(sprintf('Period %s not found.', $periodId));
        }

        return $period;
    }

    private function date(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value)->setTime(0, 0);
        } catch (Exception) {
            throw new ToolCallException(sprintf('Invalid date "%s". Use ISO-8601 format (YYYY-MM-DD).', $value));
        }
    }
}
