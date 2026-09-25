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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicReport;
use Augias\ElectronicInvoicingBundle\Enum\ReportKind;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<ElectronicReport>
 */
final class ElectronicReportRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectronicReport::class);
    }

    /**
     * The record for this source, whether it succeeded or not.
     */
    public function findForSource(Ulid $companyId, ReportKind $kind, Ulid $sourceId): ?ElectronicReport
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.company = :company')
            ->andWhere('r.kind = :kind')
            ->andWhere('r.sourceId = :source')
            ->setParameter('company', $companyId, UlidType::NAME)
            ->setParameter('kind', $kind->value)
            ->setParameter('source', $sourceId, UlidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
