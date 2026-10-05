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
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\CatchUpPlan;
use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Entity\BillPayment;
use Augias\CoreBundle\Entity\Company;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\PaymentBundle\Entity\Payment;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use function array_filter;
use function array_values;
use function count;
use function usort;

/**
 * Takes into the books what happened before they were opened.
 *
 * The books are written as things happen — see {@see LedgerFeeder} — and only
 * once a regime is chosen. An invoice issued or paid before that is in no
 * book, and nothing would ever put it there: its turnover is missing from the
 * declarations, the limits and the FEC.
 *
 * This replays the feeder over the company's documents from a date the user
 * picks, the start of the current financial year unless they say otherwise.
 * The feeder's own idempotence does the rest: whatever is in the books
 * already is left alone, so a second run adds nothing.
 *
 * Not before the lock date: what lies up to it is final, and an entry added
 * there would change figures already declared.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\BooksCatchUpTest
 */
final readonly class BooksCatchUp
{
    public function __construct(
        private LedgerFeeder $feeder,
        private AccountingProfileProvider $profileProvider,
        private LedgerLockDate $lockDate,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The first day the catch-up may start from: the day after the lock date,
     * or null when nothing is locked.
     */
    public function earliestStart(Company $company): ?DateTimeImmutable
    {
        return $this->lockDate->forCompany($company)?->modify('+1 day')->setTime(0, 0);
    }

    /**
     * The start of the current financial year, moved past the lock date when
     * that falls inside it.
     */
    public function defaultStart(Company $company, ?DateTimeImmutable $today = null): DateTimeImmutable
    {
        $profile = $this->profileProvider->forCompany($company);
        $start = PeriodType::Year->startOf($today ?? new DateTimeImmutable('today'), $profile->fiscalYearStartMonth);

        return $this->clamp($company, $start);
    }

    /**
     * The entries missing from the books for documents dated from the given
     * day, worked out with nothing written.
     *
     * @throws MathException
     */
    public function plan(Company $company, DateTimeImmutable $from): CatchUpPlan
    {
        $from = $this->clamp($company, $from->setTime(0, 0));

        $entries = $this->feeder->preview(function (LedgerFeeder $feeder) use ($company, $from): void {
            foreach ($this->candidates($company, $from) as $subject) {
                $feeder->recordFor($subject);
            }
        });

        // Candidates are picked loosely, by the dates their documents carry;
        // the entry's own date is the one that counts.
        $entries = array_values(array_filter(
            $entries,
            static fn (LedgerEntry $entry): bool => $entry->getEntryDate()->format('Y-m-d') >= $from->format('Y-m-d'),
        ));

        usort($entries, static fn (LedgerEntry $a, LedgerEntry $b): int => [$a->getEntryDate()->format('Y-m-d'), $a->getBook()->value] <=> [$b->getEntryDate()->format('Y-m-d'), $b->getBook()->value]);

        return new CatchUpPlan($from, $this->earliestStart($company), $entries);
    }

    /**
     * Writes what {@see plan()} found, and flushes.
     *
     * @return int how many entries were added
     *
     * @throws MathException
     */
    public function run(Company $company, DateTimeImmutable $from): int
    {
        $plan = $this->plan($company, $from);

        foreach ($plan->entries as $entry) {
            $this->feeder->file($entry);
        }

        $this->entityManager->flush();

        return count($plan->entries);
    }

    private function clamp(Company $company, DateTimeImmutable $date): DateTimeImmutable
    {
        $earliest = $this->earliestStart($company);

        return $earliest instanceof DateTimeImmutable && $date < $earliest ? $earliest : $date;
    }

    /**
     * Every document that may give an entry dated from the given day: the
     * ones the books are written from, as
     * {@see \Augias\AccountingBundle\Listener\Doctrine\LedgerFeedListener}
     * hands them over. Picked by the dates they carry; a refund or a
     * cancellation dated the day it is booked comes with its document.
     *
     * @return iterable<Invoice|CreditNote|Payment|CreditNoteAllocation|Bill|BillPayment>
     */
    private function candidates(Company $company, DateTimeImmutable $from): iterable
    {
        $queries = [
            [Invoice::class, 'd.invoiceDate >= :from OR d.deliveryDate >= :from', Types::DATE_IMMUTABLE],
            [CreditNote::class, 'd.creditNoteDate >= :from', Types::DATE_IMMUTABLE],
            [Bill::class, 'd.issueDate >= :from OR d.issueDate IS NULL', Types::DATE_IMMUTABLE],
            [Payment::class, 'd.completed >= :from OR d.completed IS NULL', Types::DATETIME_IMMUTABLE],
            [CreditNoteAllocation::class, 'd.allocatedOn >= :from', Types::DATE_IMMUTABLE],
            [BillPayment::class, 'd.paidDate >= :from', Types::DATE_IMMUTABLE],
        ];

        foreach ($queries as [$class, $where, $type]) {
            /** @var list<Invoice|CreditNote|Payment|CreditNoteAllocation|Bill|BillPayment> $documents */
            $documents = $this->entityManager->createQueryBuilder()
                ->select('d')
                ->from($class, 'd')
                ->where('d.company = :company')
                ->andWhere($where)
                ->setParameter('company', $company->getId(), UlidType::NAME)
                ->setParameter('from', $from, $type)
                ->getQuery()
                ->getResult();

            yield from $documents;
        }
    }
}
