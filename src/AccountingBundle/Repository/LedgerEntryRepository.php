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

namespace Augias\AccountingBundle\Repository;

use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\CoreBundle\Entity\Company;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function array_column;
use function array_map;
use function is_string;

/**
 * @extends EntityRepository<LedgerEntry>
 */
class LedgerEntryRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
    }

    /**
     * The entry already written for a given payment record, if any. This is
     * what makes the automatic feeders idempotent — it is checked before every
     * insert, and the unique index behind it is the backstop for the race.
     *
     * Takes the company explicitly so it also works from a cron command, where
     * the multi-tenancy filter is switched off.
     */
    public function findBySource(
        Company $company,
        LedgerBook $book,
        LedgerEntrySource $source,
        Ulid $sourceId,
    ): ?LedgerEntry {
        return $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->andWhere('e.book = :book')
            ->andWhere('e.source = :source')
            ->andWhere('e.sourceId = :sourceId')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('book', $book->value)
            ->setParameter('source', $source->value)
            ->setParameter('sourceId', $sourceId, UlidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every entry filed into a period, in the order they will be numbered and
     * hashed at closing time.
     *
     * @return list<LedgerEntry>
     */
    public function findForPeriod(AccountingPeriod $period): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.period = :period')
            ->setParameter('period', $period->getId(), UlidType::NAME)
            ->orderBy('e.entryDate', 'ASC')
            ->addOrderBy('e.created', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The highest sequence number handed out so far in a book, so the next
     * closing can carry on from it without a gap.
     */
    public function highestSequenceNumber(Company $company, LedgerBook $book): int
    {
        $highest = $this->createQueryBuilder('e')
            ->select('MAX(e.sequenceNumber)')
            ->andWhere('e.company = :company')
            ->andWhere('e.book = :book')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('book', $book->value)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : (int) $highest;
    }

    /**
     * The last sealed entry of a book — the head its next closing chains onto.
     */
    public function lastSealedEntry(Company $company, LedgerBook $book): ?LedgerEntry
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->andWhere('e.book = :book')
            ->andWhere('e.sequenceNumber IS NOT NULL')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('book', $book->value)
            ->orderBy('e.sequenceNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every sealed entry of a book in chain order, for
     * {@see \Augias\AccountingBundle\Service\LedgerChainVerifier}.
     *
     * @return list<LedgerEntry>
     */
    public function findSealedInChainOrder(Company $company, LedgerBook $book): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->andWhere('e.book = :book')
            ->andWhere('e.sequenceNumber IS NOT NULL')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('book', $book->value)
            ->orderBy('e.sequenceNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cash-basis turnover between two inclusive dates, grouped by activity
     * nature and currency. Grouping by currency rather than summing across it
     * is deliberate: converting silently would invent an exchange rate the
     * books never saw.
     *
     * Doctrine hydrates the enum column into an {@see ActivityNature} even in
     * an array result, which is why the nature is not a plain string here.
     *
     * @return list<array{nature: ActivityNature|null, currency: string, total: string}>
     */
    public function sumByActivityNature(
        Company $company,
        LedgerBook $book,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        /** @var list<array{nature: ActivityNature|null, currency: string, total: string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.activityNature AS nature', 'e.currencyCode AS currency', 'SUM(e.amount) AS total')
            ->andWhere('e.company = :company')
            ->andWhere('e.book = :book')
            ->andWhere('e.entryDate >= :from')
            ->andWhere('e.entryDate <= :to')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('book', $book->value)
            // Bound as dates, not as datetimes — here and in every range below.
            // `entry_date` is a DATE column, and a datetime parameter renders as
            // `2026-01-01 00:00:00`, which SQLite compares as a string against
            // `2026-01-01` and finds greater: every entry booked on the first
            // day of a range was silently dropped. MySQL and PostgreSQL coerce
            // the two and hid it.
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->groupBy('e.activityNature')
            ->addGroupBy('e.currencyCode')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * The entries of the given books between two dates, in the order a FEC
     * lists them: by date, then book, then each book's own sequence.
     *
     * @param list<LedgerBook> $books
     *
     * @return list<LedgerEntry>
     */
    public function findForFec(Company $company, DateTimeImmutable $from, DateTimeImmutable $to, array $books): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->andWhere('e.book IN (:books)')
            ->andWhere('e.entryDate >= :from')
            ->andWhere('e.entryDate <= :to')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('books', array_map(static fn (LedgerBook $book): string => $book->value, $books))
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->orderBy('e.entryDate', 'ASC')
            ->addOrderBy('e.book', 'ASC')
            ->addOrderBy('e.sequenceNumber', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Total of a book over an inclusive date range, in one currency.
     */
    public function sumForRange(
        Company $company,
        LedgerBook $book,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $currencyCode,
    ): BigInteger {
        $total = $this->createQueryBuilder('e')
            ->select('SUM(e.amount)')
            ->andWhere('e.company = :company')
            ->andWhere('e.book = :book')
            ->andWhere('e.currencyCode = :currency')
            ->andWhere('e.entryDate >= :from')
            ->andWhere('e.entryDate <= :to')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('book', $book->value)
            ->setParameter('currency', $currencyCode)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? BigInteger::zero() : BigInteger::of((string) $total);
    }

    /**
     * The tax a period's entries carried, ready to be declared.
     *
     * Read from the entries rather than from the period's frozen totals,
     * because a period that is still open has none — and the figures have to be
     * visible before the books are shut, or the user cannot see what the
     * quarter is shaping up to cost.
     *
     * Collected comes back per rate, because that is how it is declared;
     * deducted comes back as one figure, because that is how it is declared.
     *
     * @return array{collected: array<string, array{rate: string, category: string, base: BigInteger, tax: BigInteger}>, deducted: BigInteger}
     */
    public function taxForPeriod(AccountingPeriod $period): array
    {
        $company = $period->getCompany();

        // By date, not by the period an entry is filed into. A VAT return can
        // run on its own cycle — a company can seal its books quarterly and
        // declare VAT once a year — and then the entries it covers belong to
        // other periods entirely. The date the money moved is the one thing
        // both calendars agree on.
        return $this->taxForRange($company, $period->getStartDate(), $period->getEndDate());
    }

    /**
     * The tax carried by everything booked between two dates, inclusive.
     *
     * @return array{collected: array<string, array{rate: string, category: string, base: BigInteger, tax: BigInteger}>, deducted: BigInteger}
     */
    public function taxForRange(Company $company, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $collected = [];
        $deducted = BigInteger::zero();

        $entries = $this->createQueryBuilder('e')
            ->andWhere('e.company = :company')
            ->andWhere('e.entryDate >= :from')
            ->andWhere('e.entryDate <= :to')
            ->andWhere('e.taxAmount IS NOT NULL')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->orderBy('e.entryDate', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($entries as $entry) {
            $tax = $entry->getTaxAmount();

            if (! $tax instanceof BigNumber || ! $entry->getBook()->isStatutory()) {
                continue;
            }

            // The purchase register and the purchase journal each deduct what
            // became deductible on their own date — see LedgerEntry::deductedTax().
            $entryDeducted = $entry->deductedTax();

            if ($entryDeducted instanceof BigInteger) {
                $deducted = $deducted->plus($entryDeducted);

                continue;
            }

            // What fell due on this entry's date: from the sales journal, the
            // goods on the invoices issued; from the revenue book, the rest of
            // what was received. See LedgerEntry::collectedShares().
            foreach ($entry->collectedShares() as $share) {
                $key = $share['rate'] . '|' . $share['category'];
                $collected[$key] ??= [
                    'rate' => $share['rate'],
                    'category' => $share['category'],
                    'base' => BigInteger::zero(),
                    'tax' => BigInteger::zero(),
                ];
                $collected[$key]['base'] = $collected[$key]['base']->plus($share['base']);
                $collected[$key]['tax'] = $collected[$key]['tax']->plus($share['tax']);
            }
        }

        return ['collected' => $collected, 'deducted' => $deducted];
    }

    /**
     * Currencies a company has actually booked in, so the UI can point out a
     * mixed-currency ledger instead of quietly reporting one of them.
     *
     * @return list<string>
     */
    public function distinctCurrencies(Company $company): array
    {
        /** @var list<array{currencyCode: string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.currencyCode AS currencyCode')
            ->andWhere('e.company = :company')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'currencyCode');
    }

    /**
     * The date of the oldest entry in the books, or null when there are none.
     *
     * Used as the fallback lower bound when working out which periods a company
     * should have: it is the earliest date Augias has evidence the business was
     * trading on.
     */
    public function earliestEntryDate(Company $company): ?DateTimeImmutable
    {
        $date = $this->createQueryBuilder('e')
            ->select('MIN(e.entryDate)')
            ->andWhere('e.company = :company')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return is_string($date) && '' !== $date ? new DateTimeImmutable($date) : null;
    }
}
