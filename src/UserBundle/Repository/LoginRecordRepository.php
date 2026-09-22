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

namespace Augias\UserBundle\Repository;

use Augias\UserBundle\Entity\LoginRecord;
use Augias\UserBundle\Entity\User;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends EntityRepository<LoginRecord>
 */
class LoginRecordRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginRecord::class);
    }

    /**
     * One account's own attempts.
     *
     * Bounded, like every other view of a table that only grows: a page that
     * gets slower every month is a page nobody opens, which would defeat the
     * point of keeping the journal at all.
     *
     * @return list<LoginRecord>
     */
    public function forUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user->getId(), UlidType::NAME)
            ->orderBy('r.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everyone who tried to sign in to an account of the company in scope.
     *
     * The scoping is the join, not a condition written here: `CompanyFilter`
     * constrains `User` to the members of the active company, so joining the
     * user is what keeps one company out of another's journal — and it cannot
     * be forgotten the way an explicit `WHERE` can. An attempt on an address
     * that belongs to no account has no user to join, so it never appears
     * here; those are the operator's to read, not a tenant's.
     *
     * @return list<LoginRecord>
     */
    public function forCompanyMembers(int $limit = 200): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')
            ->addSelect('u')
            ->orderBy('r.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes everything older than the cut-off, and says how much it deleted.
     *
     * A single DQL delete rather than loading and removing: this runs on a
     * schedule over a table nobody prunes by hand, and hydrating a quarter of
     * a year of rows to throw them away would be the one job that runs out of
     * memory.
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.occurredAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
