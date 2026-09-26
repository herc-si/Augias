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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<Company>
 * @see \Augias\CoreBundle\Tests\Repository\CompanyRepositoryTest
 */
class CompanyRepository extends EntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CompanySelector $companySelector
    ) {
        parent::__construct($registry, Company::class);
    }

    public function updateCompanyName(string $value): void
    {
        $company = $this->companySelector->getCompany();

        if ($company instanceof Ulid) {
            $this->createQueryBuilder('c')
                ->update()
                ->set('c.name', ':name')
                ->where('c.id = :id')
                ->setParameter('name', $value)
                ->setParameter('id', $company, UlidType::NAME)
                ->getQuery()
                ->execute();
        }
    }

    public function updateCustomDomain(?string $value): ?string
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            throw new LogicException('Cannot update custom domain without an active company context.');
        }

        $company = $this->find($companyId);

        if (! $company instanceof Company) {
            throw new LogicException('Cannot update custom domain: active company could not be loaded.');
        }

        $company->setCustomDomain($value);

        $this->getEntityManager()->flush();

        return $company->getCustomDomain();
    }

    public function findOneByCustomDomain(string $host): ?Company
    {
        $host = Company::normalizeCustomDomain($host);

        if ($host === null) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->where('c.customDomain = :host')
            ->setParameter('host', $host)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Company> the companies scheduled for closure on or before $date
     */
    public function findClosingBefore(DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.closesAt IS NOT NULL')
            ->andWhere('c.closesAt <= :date')
            ->setParameter('date', $date, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    public function deleteCompany(?Ulid $companyId): void
    {
        if (! $companyId instanceof Ulid) {
            return;
        }

        $company = $this->find($companyId);

        if (! $company instanceof Company) {
            return;
        }

        $em = $this->getEntityManager();

        $em->remove($company);
        $em->flush();
    }
}
