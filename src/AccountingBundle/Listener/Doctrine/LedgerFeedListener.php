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

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Service\LedgerFeeder;
use Augias\BillBundle\Entity\BillPayment;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\PaymentBundle\Entity\Payment;
use Brick\Math\Exception\MathException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use function array_merge;

/**
 * Keeps the statutory books fed from wherever money is recorded.
 *
 * A Doctrine listener rather than a hook in the payment flow, because there is
 * no single such flow: a payment is captured by the gateway callback, by the
 * REST API, by the MCP tools and by the "record a payment" screen, and a
 * supplier payment by two more. Listening where they all necessarily end up —
 * the flush that saves them — is what makes the books complete rather than
 * complete-except-for-the-path-nobody-updated.
 *
 * Collected in `onFlush` and written in `postFlush`: the entries reference
 * their source's id, which for a newly inserted payment does not exist until
 * the insert has run.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\LedgerBookkeepingTest
 */
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
final class LedgerFeedListener
{
    /** @var list<Payment|BillPayment|CreditNoteAllocation> */
    private array $pending = [];

    /**
     * Guards the flush this listener performs of its own entries, which comes
     * straight back round to `onFlush`. Without it a book entry would queue its
     * own follow-up flush for ever.
     */
    private bool $writing = false;

    public function __construct(
        private readonly LedgerFeeder $feeder,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->writing) {
            return;
        }

        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        // Updates as well as insertions: an invoice payment is very often
        // written first and captured a moment later, and only the second of
        // those is the event the book cares about.
        foreach (array_merge($unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates()) as $entity) {
            if ($entity instanceof Payment || $entity instanceof BillPayment || $entity instanceof CreditNoteAllocation) {
                $this->pending[] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->writing || [] === $this->pending) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];
        $written = false;

        $this->writing = true;

        try {
            foreach ($pending as $subject) {
                foreach ($this->entriesFor($subject) as $entry) {
                    $written = $written || $entry instanceof LedgerEntry;
                }
            }

            if ($written) {
                $args->getObjectManager()->flush();
            }
        } finally {
            $this->writing = false;
        }
    }

    /**
     * Both feeders are asked of a payment, and each decides for itself whether
     * it has anything to write: one books a capture, the other takes it back
     * when the gateway reverses it. Asking twice is cheaper than teaching this
     * listener to read payment statuses.
     *
     * @return iterable<LedgerEntry|null>
     * @throws MathException
     */
    private function entriesFor(Payment | BillPayment | CreditNoteAllocation $subject): iterable
    {
        if ($subject instanceof BillPayment) {
            yield $this->feeder->recordBillPayment($subject);

            return;
        }

        if ($subject instanceof CreditNoteAllocation) {
            yield $this->feeder->recordCreditNoteRefund($subject);

            return;
        }

        yield $this->feeder->recordInvoicePayment($subject);
        yield $this->feeder->recordPaymentRefund($subject);
    }
}
