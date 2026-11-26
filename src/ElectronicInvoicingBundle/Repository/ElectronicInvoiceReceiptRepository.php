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

use Augias\BillBundle\Entity\Bill;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function sprintf;

/**
 * @extends EntityRepository<ElectronicInvoiceReceipt>
 */
final class ElectronicInvoiceReceiptRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectronicInvoiceReceipt::class);
    }

    /**
     * Receipts that no {@see Bill} points at yet — the ones still needing a user
     * to act. Conversion state lives on the Bill side only (there is no flag on
     * the receipt), so "pending" has to be expressed as the absence of a Bill.
     * Relies on the company filter for scoping, like any other request-time query.
     */
    public function countAwaitingBill(): int
    {
        $subQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('1')
            ->from(Bill::class, 'b')
            ->where('b.electronicInvoiceReceipt = r')
            ->getDQL();

        return (int) $this->awaitingBill($this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where(sprintf('NOT EXISTS (%s)', $subQuery)))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The receipts still needing a user to act, newest first.
     *
     * Same rule as {@see countAwaitingBill()} and for the same reason: there is
     * no flag on the receipt, so "not dealt with" is the absence of a Bill
     * pointing at it. The two must agree — a card that lists three rows under a
     * badge saying five is worse than no badge.
     *
     * @return list<ElectronicInvoiceReceipt>
     */
    public function findAwaitingBill(int $limit): array
    {
        $subQuery = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('1')
            ->from(Bill::class, 'b')
            ->where('b.electronicInvoiceReceipt = r')
            ->getDQL();

        return $this->awaitingBill($this->createQueryBuilder('r')
            ->where(sprintf('NOT EXISTS (%s)', $subQuery)))
            ->orderBy('r.created', 'DESC')
            // A poll imports a batch in one go, so `created` ties routinely and
            // the order would otherwise be whatever the database felt like. A
            // ULID is generated in time order and sorts lexicographically, so it
            // breaks the tie the same way a finer timestamp would.
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Credit notes never become a bill (CreateBillFromReceiptListener): they
     * do not wait for one.
     */
    private function awaitingBill(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->andWhere('r.documentTypeCode IS NULL OR r.documentTypeCode NOT IN (:creditNotes)')
            ->setParameter('creditNotes', ElectronicInvoiceReceipt::CREDIT_NOTE_TYPE_CODES);
    }

    /**
     * The most recent external_reference already imported for $provider/$companyId,
     * used as the cursor for {@see \Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceReceiverInterface::fetchIncoming()}
     * so each poll only asks the provider for invoices newer than what's already
     * been imported. Meant to be called with the company filter disabled, the
     * same way {@see ElectronicInvoiceSubmissionRepository::findPendingByProvider()} is.
     */
    public function findLatestExternalReference(Ulid $companyId, string $provider): ?string
    {
        $receipt = $this->createQueryBuilder('r')
            ->andWhere('r.company = :companyId')
            ->andWhere('r.provider = :provider')
            ->setParameter('companyId', $companyId, UlidType::NAME)
            ->setParameter('provider', $provider)
            ->orderBy('r.created', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $receipt instanceof ElectronicInvoiceReceipt ? $receipt->getExternalReference() : null;
    }

    public function existsForExternalReference(Ulid $companyId, string $provider, string $externalReference): bool
    {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.company = :companyId')
            ->andWhere('r.provider = :provider')
            ->andWhere('r.externalReference = :externalReference')
            ->setParameter('companyId', $companyId, UlidType::NAME)
            ->setParameter('provider', $provider)
            ->setParameter('externalReference', $externalReference)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
