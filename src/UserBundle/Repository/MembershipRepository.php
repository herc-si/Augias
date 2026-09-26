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

use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<Membership>
 */
class MembershipRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    public function findOne(User | Ulid $user, Company | Ulid $company): ?Membership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.company = :company')
            ->setParameter('user', $user instanceof User ? $user->getId() : $user, UlidType::NAME)
            ->setParameter('company', $company instanceof Company ? $company->getId() : $company, UlidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Membership>
     */
    public function forCompany(Company $company): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->innerJoin('m.user', 'u')
            ->andWhere('m.company = :company')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->orderBy('u.email')
            ->getQuery()
            ->getResult();
    }

    public function countOwners(Company $company): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.user)')
            ->andWhere('m.company = :company')
            ->andWhere('m.role = :owner')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('owner', CompanyRole::Owner->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
