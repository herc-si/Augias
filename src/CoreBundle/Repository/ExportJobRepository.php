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

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\ExportJob;
use Augias\CoreBundle\Export\Enum\ExportPurpose;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<ExportJob>
 */
final class ExportJobRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExportJob::class);
    }

    /**
     * Returns all export jobs for the currently-active company (CompanyFilter applies),
     * requested by the given user, most recent first.
     *
     * @return list<ExportJob>
     */
    public function findForUser(Ulid $userId, bool $withClosureArchives = false): array
    {
        $builder = $this->createQueryBuilder('j')
            ->setParameter('userId', $userId, UlidType::NAME);

        if ($withClosureArchives) {
            // Made for everyone who runs the company, whoever it was filed under.
            $builder
                ->andWhere('j.requestedBy = :userId OR j.purpose = :closure')
                ->setParameter('closure', ExportPurpose::Closure);
        } else {
            $builder->andWhere('j.requestedBy = :userId');
        }

        /** @var list<ExportJob> $result */
        $result = $builder
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The archives made for a company's closure, most recent first. The
     * company is named rather than left to the filter: this is read by the
     * daily task and the worker, where no company is open.
     *
     * @return list<ExportJob>
     */
    public function findClosureArchives(Company $company, ?DateTimeImmutable $since = null): array
    {
        $builder = $this->createQueryBuilder('j')
            ->andWhere('j.company = :company')
            ->andWhere('j.purpose = :closure')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('closure', ExportPurpose::Closure)
            ->orderBy('j.createdAt', 'DESC');

        if ($since instanceof DateTimeImmutable) {
            $builder->andWhere('j.createdAt >= :since')->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        /** @var list<ExportJob> $result */
        $result = $builder->getQuery()->getResult();

        return $result;
    }
}
