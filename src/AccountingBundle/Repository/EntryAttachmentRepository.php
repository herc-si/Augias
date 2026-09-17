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

namespace Augias\AccountingBundle\Repository;

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<EntryAttachment>
 */
class EntryAttachmentRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryAttachment::class);
    }

    /**
     * The documents attached to one entry, oldest first — the order they were
     * added, which is the order they are read in.
     *
     * @return list<EntryAttachment>
     */
    public function forEntry(LedgerEntry $entry): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entry = :entry')
            ->setParameter('entry', $entry->getId(), 'ulid')
            ->orderBy('a.created', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
