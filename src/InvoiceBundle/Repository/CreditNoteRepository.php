<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\InvoiceBundle\Repository;

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<CreditNote>
 */
final class CreditNoteRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreditNote::class);
    }

    /**
     * What the company still owes its clients on credit notes it has issued,
     * kept apart by currency.
     *
     * Never summed across currencies: a credit note is settled in the currency
     * it was raised in, and an exchange rate invented on a dashboard is one the
     * books never recorded.
     *
     * Two queries rather than one, for the same reason bills need two: joining
     * the allocations to the credit notes multiplies each credit note's row by
     * the number of times it was drawn on, and the totals come out inflated.
     *
     * @return array<string, BigInteger>
     * @throws MathException
     */
    public function getOutstandingByCurrency(): array
    {
        $issued = $this->createQueryBuilder('cn')
            ->select('c.currencyCode AS currencyCode', 'SUM(cn.total) AS total')
            ->innerJoin('cn.client', 'c')
            ->andWhere('cn.status = :issued')
            ->setParameter('issued', CreditNoteStatus::Issued)
            ->groupBy('c.currencyCode')
            ->getQuery()
            ->getArrayResult();

        $allocated = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.currencyCode AS currencyCode', 'SUM(a.amount) AS total')
            ->from(CreditNoteAllocation::class, 'a')
            ->innerJoin('a.creditNote', 'cn')
            ->innerJoin('cn.client', 'c')
            ->andWhere('cn.status = :issued')
            ->setParameter('issued', CreditNoteStatus::Issued)
            ->groupBy('c.currencyCode')
            ->getQuery()
            ->getArrayResult();

        $allocatedByCurrency = [];

        foreach ($allocated as $row) {
            $allocatedByCurrency[(string) $row['currencyCode']] = BigInteger::of($row['total'] ?? 0);
        }

        $outstanding = [];

        foreach ($issued as $row) {
            $currency = (string) $row['currencyCode'];

            if ('' === $currency || null === $row['total']) {
                continue;
            }

            $remaining = BigInteger::of($row['total'])
                ->minus($allocatedByCurrency[$currency] ?? BigInteger::zero());

            // A currency whose credit notes are all used up has nothing to say.
            if ($remaining->isPositive()) {
                $outstanding[$currency] = $remaining;
            }
        }

        return $outstanding;
    }

    /**
     * Issued credit notes, newest first, for a card that shows a few and defers
     * to the list for the rest.
     *
     * @return list<CreditNote>
     */
    public function getOutstanding(int $limit): array
    {
        return $this->createQueryBuilder('cn')
            ->andWhere('cn.status = :issued')
            ->setParameter('issued', CreditNoteStatus::Issued)
            ->orderBy('cn.creditNoteDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(CreditNoteStatus $status): int
    {
        return (int) $this->createQueryBuilder('cn')
            ->select('COUNT(cn.id)')
            ->andWhere('cn.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * What the company has credited its clients, and how much of that it paid
     * back, kept apart by currency for the reason
     * {@see self::getOutstandingByCurrency()} keeps them apart.
     *
     * "Credited" is every credit note that was handed over — {@see
     * CreditNoteStatus::Issued} and {@see CreditNoteStatus::Settled} both. A
     * total that counted only the first would shrink every time a client
     * actually used their credit, which is the opposite of what it measures.
     *
     * Two queries rather than one, again: joining the allocations to the credit
     * notes multiplies each credit note's row by the number of times it was
     * drawn on, and the issued total comes out inflated.
     *
     * @return array<string, array{issued: BigInteger, refunded: BigInteger}>
     */
    public function getIssuedTotalsByCurrency(): array
    {
        $issued = $this->createQueryBuilder('cn')
            ->select('c.currencyCode AS currencyCode', 'SUM(cn.total) AS total')
            ->innerJoin('cn.client', 'c')
            ->andWhere('cn.status IN (:handedOver)')
            ->setParameter('handedOver', [CreditNoteStatus::Issued, CreditNoteStatus::Settled])
            ->groupBy('c.currencyCode')
            ->getQuery()
            ->getArrayResult();

        $refunded = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('c.currencyCode AS currencyCode', 'SUM(a.amount) AS total')
            ->from(CreditNoteAllocation::class, 'a')
            ->innerJoin('a.creditNote', 'cn')
            ->innerJoin('cn.client', 'c')
            ->andWhere('a.kind = :refund')
            ->setParameter('refund', AllocationKind::Refund)
            ->groupBy('c.currencyCode')
            ->getQuery()
            ->getArrayResult();

        $refundedByCurrency = [];

        foreach ($refunded as $row) {
            $refundedByCurrency[(string) $row['currencyCode']] = BigInteger::of($row['total'] ?? 0);
        }

        $totals = [];

        foreach ($issued as $row) {
            $currency = (string) $row['currencyCode'];

            if ('' === $currency || null === $row['total']) {
                continue;
            }

            $totals[$currency] = [
                'issued' => BigInteger::of($row['total']),
                'refunded' => $refundedByCurrency[$currency] ?? BigInteger::zero(),
            ];
        }

        return $totals;
    }
}
