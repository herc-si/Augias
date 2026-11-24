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

namespace Augias\McpBundle\Repository;

use Augias\CoreBundle\Entity\Company;
use Augias\McpBundle\Entity\AssistantEvent;
use Augias\McpBundle\Enum\AssistantEventKind;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<AssistantEvent>
 */
final class AssistantEventRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantEvent::class);
    }

    /**
     * Questions the company asked since a moment: the quota's count.
     */
    public function countQuestionsSince(Company $company, DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.company = :company')
            ->andWhere('e.kind = :kind')
            ->andWhere('e.occurredAt >= :since')
            ->setParameter('company', $company->getId(), 'ulid')
            ->setParameter('kind', AssistantEventKind::Question->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.occurredAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
