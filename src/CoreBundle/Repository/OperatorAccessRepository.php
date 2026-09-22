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

use Augias\CoreBundle\Entity\OperatorAccess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OperatorAccess>
 */
final class OperatorAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OperatorAccess::class);
    }

    /**
     * The most recent reads of the company in scope.
     *
     * Scoped by the `company` filter rather than by a condition written here,
     * so this cannot accidentally answer for a company other than the one the
     * request belongs to.
     *
     * Bounded, because this table only grows and a page that gets slower every
     * month is a page nobody opens — which would defeat the point of having it.
     *
     * @return list<OperatorAccess>
     */
    public function recent(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.accessedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
