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

use Augias\AccountingBundle\Bank\ReconciliationSuggester;
use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\BankAccount;
use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\BankTransactionStatus;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Model\DeclarationResult;
use Augias\AccountingBundle\Model\LimitUsage;
use Augias\AccountingBundle\Model\PendingDeclaration;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Repository\AccountingPeriodRepository;
use Augias\AccountingBundle\Repository\BankAccountRepository;
use Augias\AccountingBundle\Repository\BankTransactionRepository;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\AccountingBundle\Service\DeclarationBuilder;
use Augias\AccountingBundle\Service\LedgerLockDate;
use Augias\AccountingBundle\Service\LimitUsageCalculator;
use Augias\AccountingBundle\Service\PendingDeclarationFinder;
use Augias\AccountingBundle\Service\TurnoverCalculator;
use Augias\CoreBundle\Entity\Company;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use DateTimeImmutable;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * The books, the turnover held against the regime's ceilings, the declarations
 * owed and the bank statements, read through MCP. Every tool here needs the
 * mcp:finance scope, granted apart on the consent page. Nothing here writes:
 * closing a period, recording a declaration or reconciling a bank line stays
 * on the screens.
 *
 * Amounts are in the minor unit (cents) of the books' currency.
 */
final readonly class FinanceTools
{
    public function __construct(
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $regimes,
        private CurrentCompany $currentCompany,
        private TurnoverCalculator $turnoverCalculator,
        private LimitUsageCalculator $limitUsageCalculator,
        private PendingDeclarationFinder $pendingDeclarations,
        private DeclarationBuilder $declarationBuilder,
        private AccountingPeriodRepository $periodRepository,
        private LedgerEntryRepository $entryRepository,
        private LedgerLockDate $lockDate,
        private BankAccountRepository $bankAccountRepository,
        private BankTransactionRepository $bankTransactionRepository,
        private ReconciliationSuggester $suggester,
        private TranslatorInterface $translator,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * The state of the books in one call: the regime, the turnover since
     * 1 January by activity, how close it is to each ceiling, the current
     * declaration period and the date the books are locked up to.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_accounting_overview', description: 'State of the books: accounting regime, VAT status, turnover since 1 January by activity, usage of each ceiling (micro-entreprise, VAT franchise), current declaration period, lock date. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function getAccountingOverview(): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $profile = $this->profileProvider->forCompany();
        $regime = $this->regimes->forProfile($profile);
        $company = $this->company();
        $today = new DateTimeImmutable('today');

        $overview = [
            'currency' => $profile->currencyCode,
            'regime' => $regime instanceof RegimeInterface ? [
                'code' => $regime->code(),
                'label' => $this->translator->trans($regime->labelKey()),
            ] : null,
            'vat_exempt' => $profile->vatExempt,
            'declaration_periodicity' => $profile->declarationPeriodicity->value,
            'fiscal_year_start_month' => $profile->fiscalYearStartMonth,
            'lock_date' => $this->lockDate->forCompany($company)?->format('Y-m-d'),
        ];

        if (! $regime instanceof RegimeInterface) {
            return $overview + ['note' => 'No accounting regime is set up for this company yet: the books are not kept.'];
        }

        $turnover = $this->turnoverCalculator->yearToDate($company, $profile->currencyCode, $today);
        $period = $this->periodRepository->findForDate($company, $profile->declarationPeriodicity, $today, $profile->fiscalYearStartMonth);

        return $overview + [
            'turnover_year_to_date' => $this->turnover($turnover),
            'ceilings' => array_map(
                fn (LimitUsage $usage): array => [
                    'label' => $this->translator->trans($usage->threshold->labelKey),
                    'ceiling' => (string) $usage->threshold->amount,
                    'nature' => $usage->threshold->nature?->value,
                    'prorated' => $usage->threshold->prorated,
                    'used_percent' => $usage->used,
                    'severity' => $usage->severity->value,
                ],
                $this->limitUsageCalculator->forTurnover($regime, $profile, $turnover, $today),
            ),
            'current_period' => $period instanceof AccountingPeriod ? $this->period($period) : null,
        ];
    }

    /**
     * Turnover recorded in the books between two dates, by activity.
     *
     * @param string $from ISO-8601 start date (inclusive)
     * @param string $to   ISO-8601 end date (inclusive)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_turnover', description: 'Turnover recorded in the books (encaissements) between two dates, by activity, in the minor unit. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function getTurnover(string $from, string $to): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $profile = $this->profileProvider->forCompany();
        $start = $this->date($from);
        $end = $this->date($to);

        if ($end < $start) {
            throw new ToolCallException('"to" is before "from".');
        }

        return $this->turnover($this->turnoverCalculator->forRange($this->company(), $profile->currencyCode, $start, $end));
    }

    /**
     * The declarations still to make, oldest first, with the amounts each
     * comes to as the books stand.
     *
     * @param int $limit Max rows (1..24)
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'list_pending_declarations', description: 'Declarations still to make (URSSAF turnover, VAT), oldest first, with the amounts due as the books stand. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function listPendingDeclarations(int $limit = 8): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $profile = $this->profileProvider->forCompany();

        if (! $this->regimes->forProfile($profile) instanceof RegimeInterface) {
            return ['results' => [], 'count' => 0];
        }

        $today = new DateTimeImmutable('today');
        $results = [];

        foreach ($this->pendingDeclarations->find($this->company(), $profile, max(1, min($limit, 24)), $today) as $pending) {
            $results[] = $this->pending($pending, $today);
        }

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * Entries of the books, newest first.
     *
     * @param string|null $from  ISO-8601 start date (inclusive)
     * @param string|null $to    ISO-8601 end date (inclusive)
     * @param string|null $book  One of: revenue, sales, purchase, bills, expense
     * @param int         $limit Max rows (1..200)
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'list_ledger_entries', description: 'Entries of the books (livre des recettes, journal des ventes, achats, dépenses), newest first, optionally between two dates and for one book. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function listLedgerEntries(?string $from = null, ?string $to = null, ?string $book = null, int $limit = 50): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $qb = $this->entryRepository->createQueryBuilder('e')
            ->orderBy('e.entryDate', 'DESC')
            ->addOrderBy('e.sequenceNumber', 'DESC')
            ->setMaxResults(max(1, min($limit, 200)));

        if ($from !== null) {
            $qb->andWhere('e.entryDate >= :from')->setParameter('from', $this->date($from)->format('Y-m-d'));
        }

        if ($to !== null) {
            $qb->andWhere('e.entryDate <= :to')->setParameter('to', $this->date($to)->format('Y-m-d'));
        }

        if ($book !== null) {
            $qb->andWhere('e.book = :book')->setParameter('book', LedgerBook::tryFrom($book) ?? throw new ToolCallException(sprintf(
                'Unknown book "%s". Valid values: %s.',
                $book,
                implode(', ', array_map(static fn (LedgerBook $case): string => $case->value, LedgerBook::cases())),
            )));
        }

        $results = array_map($this->entry(...), $qb->getQuery()->getResult());

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * Bank accounts whose statements were imported, with the number of lines
     * not yet reconciled.
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'list_bank_accounts', description: 'Bank accounts with imported statements, and how many lines of each are not reconciled yet. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function listBankAccounts(): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $results = array_map(
            fn (BankAccount $account): array => [
                'id' => $account->getId()?->toRfc4122(),
                'name' => $account->getName(),
                // The last four characters are enough to tell accounts apart.
                'iban_end' => $account->getIban() !== null ? substr(str_replace(' ', '', $account->getIban()), -4) : null,
                'currency' => $account->getCurrencyCode(),
                'unmatched_lines' => $this->bankTransactionRepository->countUnmatched($account),
            ],
            $this->bankAccountRepository->findBy([], ['name' => 'ASC']),
        );

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * Lines of a bank statement, newest first. For a line not reconciled yet,
     * the payments, invoices or purchases it most likely matches.
     *
     * @param string      $bank_account_id Bank account ULID (from list_bank_accounts)
     * @param string|null $status          One of: unmatched, matched, ignored
     * @param int         $limit           Max rows (1..200)
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'list_bank_transactions', description: 'Lines of an imported bank statement, newest first, with the likely matches of each line not reconciled yet. Reconciling stays on the screen. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function listBankTransactions(string $bank_account_id, ?string $status = null, int $limit = 50): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $account = $this->bankAccountRepository->find(UlidParser::parse($bank_account_id, 'bank_account_id'));

        if (! $account instanceof BankAccount) {
            throw new ToolCallException(sprintf('Bank account %s not found.', $bank_account_id));
        }

        $statusEnum = null;

        if ($status !== null) {
            $statusEnum = BankTransactionStatus::tryFrom($status) ?? throw new ToolCallException('status must be "unmatched", "matched" or "ignored".');
        }

        $results = array_map(
            $this->bankLine(...),
            $this->bankTransactionRepository->findForAccount($account, $statusEnum, max(1, min($limit, 200))),
        );

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * @return array<string, mixed>
     */
    private function turnover(TurnoverSummary $turnover): array
    {
        $byNature = [];

        foreach ($turnover->byNature as $nature => $amount) {
            // Entries with no activity recorded are summed under an empty key.
            $byNature[$nature === '' ? 'unclassified' : $nature] = (string) $amount;
        }

        return [
            'from' => $turnover->from->format('Y-m-d'),
            'to' => $turnover->to->format('Y-m-d'),
            'currency' => $turnover->currencyCode,
            'total' => (string) $turnover->total(),
            'by_activity' => $byNature,
            // Receipts in another currency are not converted nor counted here.
            'other_currencies_left_out' => $turnover->foreignCurrencies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function period(AccountingPeriod $period): array
    {
        return [
            'id' => $period->getId()?->toRfc4122(),
            'label' => $period->getLabel(),
            'start' => $period->getStartDate()->format('Y-m-d'),
            'end' => $period->getEndDate()->format('Y-m-d'),
            'status' => $period->getStatus()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pending(PendingDeclaration $pending, DateTimeImmutable $today): array
    {
        $row = [
            'period' => $pending->getLabel(),
            'start' => $pending->getStartDate()->format('Y-m-d'),
            'end' => ($pending->period?->getEndDate() ?? $pending->missing?->endDate)?->format('Y-m-d'),
            'days_since_end' => $pending->daysSincePeriodEnded($today),
            // What is left to do: create the period, close it, or file it.
            'action' => $pending->action->value,
            'declarations' => [],
        ];

        if (! $pending->period instanceof AccountingPeriod) {
            return $row;
        }

        try {
            $profile = $this->profileProvider->forCompany();

            foreach ($this->declarationBuilder->kindsOwed($profile, $pending->period) as $kind) {
                $row['declarations'][] = ['kind' => $kind->value] + $this->result($this->declarationBuilder->compute($pending->period, $kind));
            }
        } catch (Throwable) {
            // A figure the books cannot give yet (rates missing, say) is left
            // out rather than guessed; the screen says why.
            $row['declarations_unavailable'] = true;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(DeclarationResult $result): array
    {
        $lines = [];

        foreach ($result->lines as $line) {
            $lines[] = [
                'label' => $this->translator->trans($line->labelKey),
                'kind' => $line->kind,
                'base' => (string) $line->base,
                'rate' => (string) $line->rate,
                'amount' => (string) $line->amount,
            ];
        }

        return [
            'turnover' => (string) $result->turnover,
            'total_due' => (string) $result->totalDue(),
            'lines' => $lines,
            'warnings' => array_map(fn (string $warning): string => $this->translator->trans($warning), $result->warnings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(LedgerEntry $entry): array
    {
        return [
            'id' => $entry->getId()?->toRfc4122(),
            'book' => $entry->getBook()->value,
            'number' => $entry->getSequenceNumber(),
            'date' => $entry->getEntryDate()->format('Y-m-d'),
            'label' => $entry->getLabel(),
            'counterparty' => $entry->getCounterpartyName(),
            'document' => $entry->getDocumentReference(),
            'currency' => $entry->getCurrencyCode(),
            'amount' => (string) $entry->getAmount(),
            'net_amount' => $entry->getNetAmount()?->__toString(),
            'tax_amount' => $entry->getTaxAmount()?->__toString(),
            'activity' => $entry->getActivityNature()?->value,
            'settlement_method' => $entry->getSettlementMethod()?->value,
            'locked' => $entry->isLocked(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bankLine(BankTransaction $line): array
    {
        $row = [
            'id' => $line->getId()?->toRfc4122(),
            'date' => $line->getBookingDate()->format('Y-m-d'),
            'amount' => (string) $line->getAmount(),
            'credit' => $line->isCredit(),
            'label' => $line->getLabel(),
            'counterparty' => $line->getCounterpartyName(),
            'reference' => $line->getReference(),
            'status' => $line->getStatus()->value,
            'matched_payment_id' => $line->getPayment()?->getId()?->toRfc4122(),
            'matched_bill_payment_id' => $line->getBillPayment()?->getId()?->toRfc4122(),
        ];

        if ($line->getStatus() === BankTransactionStatus::Unmatched) {
            $row['suggestions'] = [];

            foreach (\array_slice($this->suggester->suggest($line), 0, 3) as $suggestion) {
                $row['suggestions'][] = [
                    'kind' => $suggestion->kind,
                    'id' => $suggestion->id,
                    'label' => $suggestion->label,
                    'counterparty' => $suggestion->counterparty,
                    'date' => $suggestion->date?->format('Y-m-d'),
                    'score' => $suggestion->score,
                ];
            }
        }

        return $row;
    }

    private function company(): Company
    {
        return $this->currentCompany->get() ?? throw new ToolCallException('No active company.');
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
