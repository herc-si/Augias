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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicDeclaration;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<ElectronicDeclaration>
 */
final class ElectronicDeclarationRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectronicDeclaration::class);
    }

    public function findOne(Ulid $companyId, string $provider, string $externalId): ?ElectronicDeclaration
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')
            ->andWhere('d.provider = :provider')
            ->andWhere('d.externalId = :external')
            ->setParameter('company', $companyId, UlidType::NAME)
            ->setParameter('provider', $provider)
            ->setParameter('external', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Latest period first.
     *
     * @return list<ElectronicDeclaration>
     */
    public function forCompany(Ulid $companyId, int $limit = 100): array
    {
        /** @var list<ElectronicDeclaration> $declarations */
        $declarations = $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')
            ->setParameter('company', $companyId, UlidType::NAME)
            ->orderBy('d.periodEnd', 'DESC')
            ->addOrderBy('d.kind', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $declarations;
    }
}
