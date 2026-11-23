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

use Augias\UserBundle\Entity\TrustedDevice;
use Augias\UserBundle\Entity\User;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends EntityRepository<TrustedDevice>
 */
class TrustedDeviceRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedDevice::class);
    }

    /**
     * The devices of an account still trusted, the most recently used first.
     *
     * @return list<TrustedDevice>
     */
    public function activeFor(User $user, DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.expiresAt > :now')
            ->setParameter('user', $user->getId(), UlidType::NAME)
            ->setParameter('now', $now)
            ->orderBy('d.lastUsedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function forgetAll(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.user = :user')
            ->setParameter('user', $user->getId(), UlidType::NAME)
            ->getQuery()
            ->execute();
    }

    /**
     * A trust that has run out is no use to anyone; deleted rather than kept
     * as a record of where someone was.
     */
    public function purgeExpired(DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
