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

use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodStatus;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Exception\LedgerLockedException;
use Augias\AccountingBundle\Repository\AccountingPeriodRepository;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\User;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use function array_key_first;
use function array_keys;
use function array_map;
use function count;
use function sprintf;
use function strval;

/**
 * Owns the life of an {@see AccountingPeriod}: bringing one into existence when
 * an entry first needs it, deciding which one a given entry belongs in, and
 * closing it.
 *
 * Closing is the operation everything else here exists to support, and it is
 * one-way. It numbers the period's entries gaplessly, chains them by hash, locks
 * them, and freezes the totals — after which a correction can only be a
 * reversing entry in a later, open period.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\LedgerBookkeepingTest
 */
final class AccountingPeriodManager
{
    /**
     * Periods created in this unit of work and not yet flushed, keyed by the
     * columns the table is unique on.
     *
     * {@see AccountingPeriodRepository::findForDate()} is a DQL query, so it
     * only ever sees what the database already holds. A period persisted a
     * moment ago and not yet flushed is invisible to it, and asking twice in
     * one unit of work — two payments in the same quarter, which the demo data
     * loader does routinely — used to build the period twice and fail the flush
     * on the unique constraint.
     *
     * @var array<string, AccountingPeriod>
     */
    private array $pending = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountingPeriodRepository $periodRepository,
        private LedgerEntryRepository $entryRepository,
        private LedgerEntryHasher $hasher,
    ) {
    }

    /**
     * The period covering a date, created if it does not exist yet.
     *
     * Periods are made lazily rather than generated ahead of time: a company
     * that has booked nothing has no periods, and one that books a payment
     * dated two years back gets that period brought into being on the spot
     * instead of the entry landing nowhere.
     *
     * The new period is persisted but not flushed — the caller is inside a
     * flush of its own more often than not.
     */
    public function periodFor(
        Company $company,
        PeriodType $type,
        DateTimeImmutable $date,
        int $fiscalYearStartMonth = 1,
    ): AccountingPeriod {
        $existing = $this->periodRepository->findForDate($company, $type, $date, $fiscalYearStartMonth);

        if ($existing instanceof AccountingPeriod) {
            return $existing;
        }

        $year = $type->yearOf($date, $fiscalYearStartMonth);
        $ordinal = $type->ordinalOf($date);
        $key = sprintf('%s|%s|%d|%d', strval($company->getId()), $type->value, $year, $ordinal);

        // The entity manager is cleared between units of work, which detaches
        // anything remembered here — so a hit is only trusted while the period
        // is still managed.
        $pending = $this->pending[$key] ?? null;

        if ($pending instanceof AccountingPeriod && $this->entityManager->contains($pending)) {
            return $pending;
        }

        $period = new AccountingPeriod()
            ->setType($type)
            ->setYear($year)
            ->setOrdinal($ordinal)
            ->setStartDate($type->startOf($date, $fiscalYearStartMonth))
            ->setEndDate($type->endOf($date, $fiscalYearStartMonth));

        $period->setCompany($company);

        $this->entityManager->persist($period);

        $this->pending[$key] = $period;

        return $period;
    }

    /**
     * Files an entry into the right period, and says so when that is not the
     * period its own date calls for.
     *
     * A payment can perfectly well arrive dated inside a period that has
     * already been closed — a bank transfer recorded late, an invoice marked
     * paid after the quarter was sealed. The closed period cannot take it, so
     * the entry goes into the earliest period still open and is flagged
     * {@see LedgerEntry::isLateEntry()}. The date stays truthful and the
     * discrepancy stays visible, which is the only honest way to handle it.
     */
    public function assignPeriod(LedgerEntry $entry, PeriodType $type, int $fiscalYearStartMonth = 1): void
    {
        $company = $entry->getCompany();
        $period = $this->periodFor($company, $type, $entry->getEntryDate(), $fiscalYearStartMonth);

        if ($period->isOpen()) {
            $entry->setPeriod($period)
                ->setLateEntry(false);

            return;
        }

        // Its own period is sealed. The earliest open one is where a late entry
        // belongs; if every existing period is closed, today's is created — it
        // is open by construction, since closing runs in date order.
        $fallback = $this->periodRepository->findEarliestOpenPeriod($company, $type)
            ?? $this->periodFor($company, $type, new DateTimeImmutable('today'), $fiscalYearStartMonth);

        $entry->setPeriod($fallback)
            ->setLateEntry(true);
    }

    /**
     * Seals a period.
     *
     * @throws LedgerLockedException when the period is already closed, has not
     *                               ended yet, or an earlier one is still open —
     *                               closing out of order would tear a hole in
     *                               the numbering and break the chain
     */
    public function close(AccountingPeriod $period, ?User $closedBy = null, ?DateTimeImmutable $on = null): void
    {
        if ($period->isClosed()) {
            throw LedgerLockedException::forPeriod($period);
        }

        // A period still running cannot be sealed. Money received before its end
        // date still belongs in it, and a sealed period cannot take it: the
        // entry would be filed into a closed period as a late one, without a
        // sequence number and outside the hash chain the seal just computed.
        //
        // assignPeriod() already assumes this cannot happen — "it is open by
        // construction, since closing runs in date order" — and until now
        // nothing made that true.
        if ($period->getEndDate() >= ($on ?? new DateTimeImmutable('today'))->setTime(0, 0)) {
            throw LedgerLockedException::periodHasNotEnded($period);
        }

        if ($this->periodRepository->hasOpenPeriodBefore($period)) {
            throw LedgerLockedException::earlierPeriodStillOpen($period);
        }

        $company = $period->getCompany();
        $entries = $this->entryRepository->findForPeriod($period);
        $sealedAt = new DateTimeImmutable();
        $lastHash = null;

        // Per book: the numbering and the chain are per book, since the two are
        // separate statutory registers that happen to share a table.
        $nextNumber = [];
        $previousHash = [];

        foreach ($entries as $entry) {
            $book = $entry->getBook()->value;

            if (! isset($nextNumber[$book])) {
                $nextNumber[$book] = $this->entryRepository->highestSequenceNumber($company, $entry->getBook()) + 1;
                $previousHash[$book] = $this->entryRepository
                    ->lastSealedEntry($company, $entry->getBook())
                    ?->getHash();
            }

            $entry->setSequenceNumber($nextNumber[$book]++)
                ->setPreviousHash($previousHash[$book])
                ->setLockedAt($sealedAt);

            $hash = $this->hasher->hash($entry, $previousHash[$book]);
            $entry->setHash($hash);

            $previousHash[$book] = $hash;
            $lastHash = $hash;
        }

        $period->setStatus(PeriodStatus::Closed)
            ->setClosedAt($sealedAt)
            ->setClosedBy($closedBy)
            ->setEntryCount(count($entries))
            ->setClosingHash($lastHash)
            ->setTotals($this->totalsFor($period, $entries));

        $this->entityManager->flush();
    }

    /**
     * The figures frozen onto the period at closing time: revenue split by
     * activity nature, purchases as one total, all in minor units as strings.
     *
     * Computed from the entries in hand rather than re-queried, so what is
     * stored is exactly what was sealed.
     *
     * @param list<LedgerEntry> $entries
     * @return array<string, mixed>
     */
    private function totalsFor(AccountingPeriod $period, array $entries): array
    {
        $revenue = [];
        $purchase = BigInteger::zero();
        $currencies = [];
        $taxByRate = [];
        $collected = null;
        $deductible = null;

        foreach ($entries as $entry) {
            // Expenses are nobody's declaration: they are kept for the owner,
            // not for the administration, and counting them here would put
            // them in the turnover of a period and their VAT among the tax
            // collected — the two numbers a return is made of.
            if (! $entry->getBook()->isStatutory()) {
                continue;
            }

            $currencies[$entry->getCurrencyCode()] = true;
            $tax = $entry->getTaxAmount();

            if ($entry->getBook() === LedgerBook::Purchase) {
                $purchase = $purchase->plus($entry->getAmount());

                // Kept apart from the tax collected, and never added to it: on
                // a return the two are opposite signs, and one figure standing
                // for both would be the difference between them, which is what
                // the return is for computing.
                if ($tax instanceof BigNumber) {
                    $deductible = ($deductible ?? BigInteger::zero())->plus($tax);
                }

                continue;
            }

            if ($tax instanceof BigNumber) {
                $collected = ($collected ?? BigInteger::zero())->plus($tax);
            }

            // Frozen per rate, because that is how tax collected is declared.
            // Left out entirely for a period whose entries carried none, so a
            // company outside the scope of VAT gets no zero to explain.
            foreach ($entry->getTaxBreakdown() ?? [] as $share) {
                $key = $share['rate'] . '|' . $share['category'];
                $taxByRate[$key] ??= ['base' => BigInteger::zero(), 'tax' => BigInteger::zero()];
                $taxByRate[$key]['base'] = $taxByRate[$key]['base']->plus($share['base']);
                $taxByRate[$key]['tax'] = $taxByRate[$key]['tax']->plus($share['tax']);
            }

            $nature = $entry->getActivityNature();
            $nature = $nature instanceof ActivityNature ? $nature->value : 'unspecified';
            $revenue[$nature] = ($revenue[$nature] ?? BigInteger::zero())->plus($entry->getAmount());
        }

        $totals = [
            'currency' => array_key_first($currencies) ?? '',
            // More than one means the totals above are a mix of currencies and
            // cannot be read as a single figure. Recorded rather than resolved:
            // the books say what they say, and a rate invented at closing time
            // would be worse than a flag.
            'currencies' => array_keys($currencies),
            'revenue' => array_map(strval(...), $revenue),
            'purchase' => (string) $purchase,
            'label' => $period->getLabel(),
        ];

        if ($collected instanceof BigInteger) {
            $totals['tax_collected'] = (string) $collected;
            $totals['tax_by_rate'] = array_map(
                static fn (array $share): array => ['base' => (string) $share['base'], 'tax' => (string) $share['tax']],
                $taxByRate,
            );
        }

        if ($deductible instanceof BigInteger) {
            $totals['tax_deductible'] = (string) $deductible;
        }

        return $totals;
    }
}
