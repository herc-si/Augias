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

use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Enum\RecordKind;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<DocumentActivity>
 */
class DocumentActivityRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentActivity::class);
    }

    /**
     * A document's history, most recent first. Bounded: a quote opened every
     * day for a year should not make its page slow.
     *
     * @return list<DocumentActivity>
     */
    public function forDocument(RecordKind $kind, Ulid $recordId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.kind = :kind')
            ->andWhere('a.recordId = :record')
            ->setParameter('kind', $kind->value)
            ->setParameter('record', $recordId, UlidType::NAME)
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether the same thing already happened to the document since then: a
     * client who reloads the page has not read the quote twice.
     */
    public function hasSince(RecordKind $kind, Ulid $recordId, DocumentActivityType $type, DateTimeImmutable $since): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere('a.kind = :kind')
            ->andWhere('a.recordId = :record')
            ->andWhere('a.type = :type')
            ->andWhere('a.occurredAt >= :since')
            ->setParameter('kind', $kind->value)
            ->setParameter('record', $recordId, UlidType::NAME)
            ->setParameter('type', $type->value)
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
