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

namespace Augias\AccountingBundle\Listener\Doctrine;

use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use function array_merge;
use function in_array;

/**
 * Freezes the company's option for VAT on debits onto a document the moment
 * it is issued.
 *
 * Here rather than at each place a document gets issued — a workflow
 * transition for an invoice, three separate actions for a credit note —
 * because missing one of them would issue a document that neither prints the
 * mention nor has its tax declared on the right day. Whatever path issued it,
 * it is flushed.
 *
 * Once only: a document that already carries a value keeps it, whatever the
 * setting says later.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\VatOnDebitsTest
 */
#[AsDoctrineListener(Events::onFlush)]
final readonly class VatOnDebitsSnapshotListener
{
    /**
     * The places an invoice reaches only by having been issued.
     *
     * @var list<InvoiceStatus>
     */
    private const array ISSUED = [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid];

    public function __construct(
        private AccountingProfileProvider $profileProvider,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        foreach (array_merge($unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates()) as $entity) {
            if (! $entity instanceof Invoice && ! $entity instanceof CreditNote) {
                continue;
            }

            if ($entity->hasVatOnDebitsDecided() || ! $this->isIssued($entity)) {
                continue;
            }

            $entity->setVatOnDebits($this->profileProvider->isVatOnDebits($entity->getCompany()));

            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($entity::class), $entity);
        }
    }

    private function isIssued(Invoice | CreditNote $document): bool
    {
        return $document instanceof CreditNote
            ? $document->isIssued()
            : in_array($document->getStatus(), self::ISSUED, true);
    }
}
