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
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
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

    /**
     * What credit notes have taken off an invoice: the offsets set against it.
     * Not money, so not a payment, but no longer owed either.
     */
    public function offsetTotalForInvoice(Invoice $invoice): BigNumber
    {
        $id = $invoice->getId();

        if (! $id instanceof Ulid) {
            return BigInteger::zero();
        }

        $total = $this->createQueryBuilder('a')
            ->select('SUM(a.amount)')
            ->where('a.invoice = :invoice')
            ->andWhere('a.kind = :offset')
            ->setParameter('invoice', $id, UlidType::NAME)
            ->setParameter('offset', AllocationKind::Offset)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? BigInteger::zero() : BigNumber::of((string) $total);
    }

    /**
     * The part of the offsets on an invoice that comes from credit notes raised
     * against that very invoice: its corrections, as opposed to credit carried
     * over from other documents.
     */
    public function ownOffsetTotalForInvoice(Invoice $invoice): BigNumber
    {
        $id = $invoice->getId();

        if (! $id instanceof Ulid) {
            return BigInteger::zero();
        }

        $total = $this->createQueryBuilder('a')
            ->select('SUM(a.amount)')
            ->join('a.creditNote', 'c')
            ->where('a.invoice = :invoice')
            ->andWhere('a.kind = :offset')
            ->andWhere('c.creditedInvoice = :invoice')
            ->setParameter('invoice', $id, UlidType::NAME)
            ->setParameter('offset', AllocationKind::Offset)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? BigInteger::zero() : BigNumber::of((string) $total);
    }
}
