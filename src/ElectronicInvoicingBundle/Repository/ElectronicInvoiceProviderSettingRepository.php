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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<ElectronicInvoiceProviderSetting>
 */
final class ElectronicInvoiceProviderSettingRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectronicInvoiceProviderSetting::class);
    }

    /**
     * The provider invoices go through — active, and not refused by its
     * platform. One whose platform has not verified the company's identity is
     * left out: it would refuse everything, so as far as the rest of the
     * application is concerned, electronic invoicing is off.
     */
    public function findActive(): ?ElectronicInvoiceProviderSetting
    {
        return $this->usable()
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Meant to be called with the company filter disabled, where `findActive()`
     * (which relies on that filter to scope to "the current company") cannot
     * be used, since the company must be given explicitly instead.
     */
    public function findActiveForCompany(Ulid $companyId, ?string $provider = null): ?ElectronicInvoiceProviderSetting
    {
        $qb = $this->usable()
            ->andWhere('s.company = :company')
            ->setParameter('company', $companyId, UlidType::NAME)
            ->setMaxResults(1);

        if (null !== $provider) {
            $qb->andWhere('s.provider = :provider')->setParameter('provider', $provider);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Every setting switched on, verified or not, across whatever companies
     * the caller can see — what the hourly account check goes through.
     *
     * @return list<ElectronicInvoiceProviderSetting>
     */
    public function findSwitchedOn(): array
    {
        return $this->findBy(['active' => true]);
    }

    private function usable(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.active = :active')
            ->andWhere('s.accountVerification IS NULL OR s.accountVerification = :verified')
            ->setParameter('active', true)
            ->setParameter('verified', AccountVerification::Verified->value);
    }

    public function delete(ElectronicInvoiceProviderSetting $setting): void
    {
        $this->getEntityManager()->remove($setting);
        $this->getEntityManager()->flush();
    }
}
