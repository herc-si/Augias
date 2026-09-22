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

namespace Augias\CoreBundle\Repository;

use Augias\CoreBundle\Entity\RecordAccess;
use Augias\UserBundle\Entity\User;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends EntityRepository<RecordAccess>
 */
class RecordAccessRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecordAccess::class);
    }

    /**
     * What this person opened, most recent first.
     *
     * The company filter already keeps one tenant out of another's rows; the
     * user is named here because this journal is a person's own trail and not
     * the company's view of its people. Two guards, two different questions.
     *
     * Bounded: the table only grows, and a page that gets slower every month
     * is a page nobody opens.
     *
     * @return list<RecordAccess>
     */
    public function forUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user->getId(), UlidType::NAME)
            ->orderBy('a.openedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Forgets everything past the cut-off, in one statement rather than by
     * hydrating a quarter of a year of rows to throw them away.
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.openedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
