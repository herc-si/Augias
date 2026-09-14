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

namespace Augias\InvoiceBundle\Repository;

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends EntityRepository<CreditNoteAllocation>
 */
final class CreditNoteAllocationRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreditNoteAllocation::class);
    }

    /**
     * What has already been taken off a credit note, summed in the database
     * rather than by walking the collection: the caller needs this before every
     * allocation, and a credit note used up in many small goes would otherwise
     * hydrate every one of them to add up a single figure.
     */
    public function allocatedTotal(CreditNote $creditNote): BigNumber
    {
        $id = $creditNote->getId();

        if (! $id instanceof Ulid) {
            return BigInteger::zero();
        }

        // Bound as a ULID rather than as the entity: the identifier is a custom
        // binary type, and handing Doctrine the object matches nothing.
        $total = $this->createQueryBuilder('a')
            ->select('SUM(a.amount)')
            ->where('a.creditNote = :creditNote')
            ->setParameter('creditNote', $id, UlidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? BigInteger::zero() : BigNumber::of((string) $total);
    }
}
