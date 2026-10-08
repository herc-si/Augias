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
     * client who reloads the page has not read the quote twice. A visit by a
     * mail filter and one by a person are not the same thing, though: the
     * filter opening the link on arrival must not hide the client opening it
     * a minute later.
     */
    public function hasSince(RecordKind $kind, Ulid $recordId, DocumentActivityType $type, DateTimeImmutable $since, bool $automated): bool
    {
        /** @var list<DocumentActivity> $recent */
        $recent = $this->createQueryBuilder('a')
            ->andWhere('a.kind = :kind')
            ->andWhere('a.recordId = :record')
            ->andWhere('a.type = :type')
            ->andWhere('a.occurredAt >= :since')
            ->setParameter('kind', $kind->value)
            ->setParameter('record', $recordId, UlidType::NAME)
            ->setParameter('type', $type->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        foreach ($recent as $entry) {
            if ($entry->isLikelyAutomated() === $automated) {
                return true;
            }
        }

        return false;
    }

    /**
     * What happened between a document and its client — sent, failed, opened,
     * answered — most recent first: what the "Activity" column of a list sums
     * up in one badge. Status steps are left out; the list has its own column
     * for those.
     *
     * @return list<DocumentActivity>
     */
    public function clientSideFor(RecordKind $kind, Ulid $recordId, int $limit = 30): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.kind = :kind')
            ->andWhere('a.recordId = :record')
            ->andWhere('a.type IN (:types)')
            ->setParameter('kind', $kind->value)
            ->setParameter('record', $recordId, UlidType::NAME)
            ->setParameter('types', self::clientSideTypes())
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The company's latest client-side events, every document together: the
     * dashboard's "Activity" card. The company filter keeps it to the company.
     *
     * @return list<DocumentActivity>
     */
    public function recentClientSide(int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.type IN (:types)')
            ->setParameter('types', self::clientSideTypes())
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countFailedSince(DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.type = :type')
            ->andWhere('a.occurredAt >= :since')
            ->setParameter('type', DocumentActivityType::SendFailed->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<string>
     */
    private static function clientSideTypes(): array
    {
        return array_map(
            static fn (DocumentActivityType $type): string => $type->value,
            array_values(array_filter(DocumentActivityType::cases(), static fn (DocumentActivityType $type): bool => DocumentActivityType::Status !== $type)),
        );
    }
}
