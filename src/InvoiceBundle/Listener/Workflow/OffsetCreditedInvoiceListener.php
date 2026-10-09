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

namespace Augias\InvoiceBundle\Listener\Workflow;

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Augias\InvoiceBundle\Service\InvoiceSettlement;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use function in_array;

/**
 * A credit note raised against an invoice the client has not paid yet is set
 * against it as soon as it is issued.
 *
 * That is what the credit note is for: an issued invoice is not cancelled, it
 * is credited, and the client owes the difference. Left to a separate gesture,
 * the invoice kept its full balance, went overdue and was chased. A paid
 * invoice is left alone: there the credit is money to give back or to deduct
 * later, which is the user's call.
 *
 * Runs after {@see CreditIssuedCreditNoteListener}, which puts the amount on
 * the client's credit that the offset then takes off.
 */
final readonly class OffsetCreditedInvoiceListener
{
    private const array UNPAID = [InvoiceStatus::Pending, InvoiceStatus::Overdue];

    public function __construct(
        private CreditNoteAllocator $allocator,
        private InvoiceSettlement $settlement,
    ) {
    }

    /**
     * @param CompletedEvent<CreditNote> $event
     * @throws MathException
     */
    #[AsEventListener('workflow.credit_note.completed.' . CreditNoteGraph::TRANSITION_ISSUE, priority: -10)]
    public function onIssued(CompletedEvent $event): void
    {
        $creditNote = $event->getSubject();

        if (! $creditNote instanceof CreditNote) {
            return;
        }

        $invoice = $creditNote->getCreditedInvoice();

        if (! $invoice instanceof Invoice || ! in_array($invoice->getStatus(), self::UNPAID, true)) {
            return;
        }

        $amount = BigDecimal::min(
            $this->settlement->balance($invoice),
            $this->allocator->remaining($creditNote)->toBigDecimal(),
        );

        if (! $amount->isPositive()) {
            return;
        }

        $this->allocator->allocate($creditNote, AllocationKind::Offset, $amount, $invoice);
    }
}
