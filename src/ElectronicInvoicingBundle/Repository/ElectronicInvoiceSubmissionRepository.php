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

namespace Augias\ElectronicInvoicingBundle\Repository;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<ElectronicInvoiceSubmission>
 */
final class ElectronicInvoiceSubmissionRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectronicInvoiceSubmission::class);
    }

    /**
     * Successful submissions for $provider still worth asking about: not yet
     * at one of $finalStatusCodes, and — once at one of $settledStatusCodes,
     * where only the payment is still to come — sent since $followedSince.
     * Meant to run with the company filter disabled, so it must be called
     * across every company, not just the current one.
     *
     * @param list<string> $finalStatusCodes
     * @param list<string> $settledStatusCodes
     *
     * @return list<ElectronicInvoiceSubmission>
     */
    public function findPendingByProvider(string $provider, array $finalStatusCodes, array $settledStatusCodes = [], ?DateTimeImmutable $followedSince = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.provider = :provider')
            ->andWhere('s.success = true')
            ->setParameter('provider', $provider)
            ->setParameter('finalStatusCodes', $finalStatusCodes);

        if ([] === $settledStatusCodes || ! $followedSince instanceof DateTimeImmutable) {
            return $qb
                ->andWhere('s.statusCode IS NULL OR s.statusCode NOT IN (:finalStatusCodes)')
                ->getQuery()
                ->getResult();
        }

        return $qb
            ->andWhere('s.statusCode IS NULL OR (s.statusCode NOT IN (:finalStatusCodes) AND (s.statusCode NOT IN (:settledStatusCodes) OR s.created >= :followedSince))')
            ->setParameter('settledStatusCodes', $settledStatusCodes)
            ->setParameter('followedSince', $followedSince)
            ->getQuery()
            ->getResult();
    }

    /**
     * The most recent transmissions for the current company, newest first.
     *
     * Scoped by the company filter like any request-time query, unlike
     * {@see findPendingByProvider()} which the polling command runs with that
     * filter switched off.
     *
     * The invoice is joined and selected because the caller names it on every
     * row, and a lazy proxy per submission would be one query each.
     *
     * @return list<ElectronicInvoiceSubmission>
     */
    public function findRecent(int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.invoice', 'i')
            ->addSelect('i')
            ->orderBy('s.created', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Transmissions the platform refused outright.
     *
     * A rejection is the only outcome here the user has to do something about,
     * and unlike a pending one it does not resolve itself — so it is counted
     * separately rather than left to be spotted in a list.
     */
    public function countFailed(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.success = false')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
