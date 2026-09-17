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

namespace Augias\BillBundle\Repository;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Entity\BillPayment;
use Augias\BillBundle\Enum\BillStatus;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<Bill>
 */
class BillRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bill::class);
    }

    /**
     * Pending bills whose due date has passed — candidates for the overdue
     * cron, meant to run with the company filter disabled, the same as
     * {@see \Augias\InvoiceBundle\Repository\InvoiceRepository::getPendingOverdueInvoices()}.
     *
     * Nothing calls this yet: there is a `MarkOverdueInvoicesCommand` and no
     * equivalent for bills, so no supplier invoice is ever marked overdue. That
     * is a feature that was never finished rather than one that broke, and it
     * is noted here rather than in a commit message nobody will read again.
     *
     * Passed, not reached: a bill is not late on the day it falls due. The date
     * is bound as a date for that to hold — `due_date` is a DATE column, and a
     * datetime parameter renders as `2026-03-31 00:00:00`, which SQLite
     * compares as a string against `2026-03-31` and finds greater.
     *
     * @return list<Bill>
     */
    public function getPendingOverdueBills(): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.dueDate IS NOT NULL')
            ->andWhere('b.dueDate < :today')
            ->setParameter('status', BillStatus::Pending->value)
            ->setParameter('today', new DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    public function delete(Bill $bill): void
    {
        $this->getEntityManager()->remove($bill);
        $this->getEntityManager()->flush();
    }

    /**
     * Bills still owed, oldest due date first.
     *
     * The supplier is joined and selected because the caller renders its name
     * for every row, and a lazy proxy per bill would be one query each.
     *
     * @return list<Bill>
     */
    public function getUnpaidBills(int $limit = 5): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin('b.supplier', 's')
            ->addSelect('s')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('statuses', [BillStatus::Overdue->value, BillStatus::Pending->value])
            // Nulls last: a bill with no due date is owed but not late, and it
            // has no business sorting ahead of one that is.
            ->orderBy('CASE WHEN b.dueDate IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('b.dueDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(BillStatus $status): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.status = :status')
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * What is still owed to suppliers, per currency, in minor units.
     *
     * Two queries rather than one, and deliberately: a Bill has no balance
     * column — what is left to pay is the total minus the payments recorded
     * against it — and joining the payments in to subtract them would multiply
     * each bill's row by its number of payments, inflating the total it was
     * meant to reduce. Summing the two sides separately cannot fan out.
     *
     * Bills are counted in their own currency and never converted; an exchange
     * rate invented here would be one the books never recorded.
     *
     * @return array<string, BigInteger>
     */
    public function getOutstandingByCurrency(): array
    {
        $statuses = [BillStatus::Overdue->value, BillStatus::Pending->value];

        $billed = $this->createQueryBuilder('b')
            ->select('b.currencyCode AS currencyCode', 'SUM(b.totalAmount) AS total')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->groupBy('b.currencyCode')
            ->getQuery()
            ->getArrayResult();

        $paid = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('b.currencyCode AS currencyCode', 'SUM(p.amount) AS total')
            ->from(BillPayment::class, 'p')
            ->innerJoin('p.bill', 'b')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->groupBy('b.currencyCode')
            ->getQuery()
            ->getArrayResult();

        $paidByCurrency = [];

        foreach ($paid as $row) {
            $paidByCurrency[(string) $row['currencyCode']] = BigInteger::of($row['total'] ?? 0);
        }

        $outstanding = [];

        foreach ($billed as $row) {
            $currency = (string) $row['currencyCode'];

            if ('' === $currency || null === $row['total']) {
                continue;
            }

            $balance = BigInteger::of($row['total'])
                ->minus($paidByCurrency[$currency] ?? BigInteger::zero());

            // Overpaid is not negative money owed. Bill::getBalance() takes the
            // same view one bill at a time, and the total has to agree with the
            // rows the user can see under it.
            if ($balance->isNegative()) {
                $balance = BigInteger::zero();
            }

            if (! $balance->isZero()) {
                $outstanding[$currency] = $balance;
            }
        }

        return $outstanding;
    }
}
