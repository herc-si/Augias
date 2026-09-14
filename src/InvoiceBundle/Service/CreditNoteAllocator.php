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

namespace Augias\InvoiceBundle\Service;

use Augias\ClientBundle\Repository\CreditRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Repository\CreditNoteAllocationRepository;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Records what became of a credit note.
 *
 * An issued credit note is a debt towards the client, and it is settled in one
 * of two ways: set against what they owe on another invoice, or paid back. Both
 * are written down here, because the client's credit balance is a single number
 * and cannot answer which invoice consumed which credit note.
 *
 * @see \Augias\InvoiceBundle\Tests\Service\CreditNoteAllocatorTest
 */
final readonly class CreditNoteAllocator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CreditNoteAllocationRepository $allocations,
        private CreditRepository $credits,
        private WorkflowInterface $creditNoteStateMachine,
        private ClockInterface $clock,
    ) {
    }

    /**
     * What is still owed on this credit note.
     *
     * @throws MathException
     */
    public function remaining(CreditNote $creditNote): BigNumber
    {
        return $creditNote->getTotal()
            ->toBigDecimal()
            ->minus($this->allocations->allocatedTotal($creditNote));
    }

    /**
     * @throws AllocationException when the credit note owes nothing, owes less
     *                             than this, or the target does not match the kind
     * @throws MathException
     */
    public function allocate(
        CreditNote $creditNote,
        AllocationKind $kind,
        BigNumber | int | string $amount,
        ?Invoice $invoice = null,
        ?DateTimeImmutable $on = null,
        ?string $notes = null,
    ): CreditNoteAllocation {
        // A draft owes nothing: it has not been handed over, and the client has
        // no claim on a document they have never seen.
        if (! $creditNote->isIssued()) {
            throw AllocationException::notIssued($creditNote);
        }

        $amount = BigNumber::of($amount);

        if ($amount->isNegativeOrZero()) {
            throw AllocationException::notPositive();
        }

        $this->assertTargetMatchesKind($creditNote, $kind, $invoice);

        $remaining = $this->remaining($creditNote);

        if ($amount->isGreaterThan($remaining)) {
            throw AllocationException::exceedsRemaining($creditNote, $remaining);
        }

        $allocation = new CreditNoteAllocation()
            ->setCreditNote($creditNote)
            ->setInvoice($invoice)
            ->setKind($kind)
            ->setAmount($amount)
            ->setAllocatedOn($on ?? $this->clock->now())
            ->setNotes($notes);

        $allocation->setCompany($creditNote->getCompany());
        $creditNote->addAllocation($allocation);

        $this->entityManager->persist($allocation);

        // Issuing put this amount on the client's balance; using it takes the
        // same amount back off. An unused credit note is exactly the part of
        // the balance that is still owed.
        $this->credits->deductCredit($creditNote->getClient(), $amount);

        if ($amount->isEqualTo($remaining) && $this->creditNoteStateMachine->can($creditNote, CreditNoteGraph::TRANSITION_SETTLE)) {
            $this->creditNoteStateMachine->apply($creditNote, CreditNoteGraph::TRANSITION_SETTLE);
        }

        $this->entityManager->flush();

        return $allocation;
    }

    private function assertTargetMatchesKind(CreditNote $creditNote, AllocationKind $kind, ?Invoice $invoice): void
    {
        if (AllocationKind::Offset === $kind) {
            if (! $invoice instanceof Invoice) {
                throw AllocationException::offsetNeedsAnInvoice();
            }

            // Credit belongs to the client it was raised for. Setting it
            // against someone else's invoice would move money between accounts
            // with nothing to show for it.
            if ($invoice->getClient()?->getId()?->toBinary() !== $creditNote->getClient()->getId()?->toBinary()) {
                throw AllocationException::wrongClient($creditNote);
            }

            return;
        }

        if ($invoice instanceof Invoice) {
            throw AllocationException::refundTakesNoInvoice();
        }
    }
}
