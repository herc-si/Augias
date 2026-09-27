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

use Augias\AccountingBundle\Entity\BankAccount;
use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\AccountingBundle\Enum\BankTransactionStatus;
use Augias\BillBundle\Entity\BillPayment;
use Augias\PaymentBundle\Entity\Payment;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use function array_fill_keys;

/**
 * @extends EntityRepository<BankTransaction>
 */
class BankTransactionRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankTransaction::class);
    }

    /**
     * @return list<BankTransaction> most recent first
     */
    public function findForAccount(BankAccount $account, ?BankTransactionStatus $status = null, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.bankAccount = :account')
            ->setParameter('account', $account->getId(), UlidType::NAME)
            ->orderBy('t.bookingDate', 'DESC')
            ->addOrderBy('t.created', 'DESC')
            ->setMaxResults($limit);

        if ($status instanceof BankTransactionStatus) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status->value);
        }

        /** @var list<BankTransaction> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<string> $fingerprints
     *
     * @return array<string, true> the fingerprints already imported on this account
     */
    public function knownFingerprints(BankAccount $account, array $fingerprints): array
    {
        if ([] === $fingerprints) {
            return [];
        }

        /** @var list<string> $known */
        $known = $this->createQueryBuilder('t')
            ->select('t.fingerprint')
            ->andWhere('t.bankAccount = :account')
            ->andWhere('t.fingerprint IN (:fingerprints)')
            ->setParameter('account', $account->getId(), UlidType::NAME)
            ->setParameter('fingerprints', $fingerprints)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($known, true);
    }

    public function countUnmatched(BankAccount $account): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.bankAccount = :account')
            ->andWhere('t.status = :status')
            ->setParameter('account', $account->getId(), UlidType::NAME)
            ->setParameter('status', BankTransactionStatus::Unmatched->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function isPaymentMatched(Payment $payment): bool
    {
        return null !== $this->findOneBy(['payment' => $payment]);
    }

    public function isBillPaymentMatched(BillPayment $payment): bool
    {
        return null !== $this->findOneBy(['billPayment' => $payment]);
    }
}
