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

namespace Augias\InvoiceBundle\Listener;

use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * An invoice takes its number when it is finalised, a credit note when it is
 * issued — not when it is drafted.
 *
 * Numbered at creation, a draft left aside kept a number while later invoices
 * took the next ones: issued in a different order from their numbers, and a
 * gap in the series once the draft was dropped (08/10/2026). French law asks
 * for one continuous, chronological series; a draft is not part of it.
 *
 * On the transition itself, before the document enters its new place: the
 * listeners of that place (the books, electronic invoicing) see the number.
 * A document that already has one keeps it — those drafted before this
 * change, or numbered by whoever created them (the subscription invoices,
 * the API).
 *
 * Two documents finalised at the same moment could still be handed the same
 * number: the unique index on (company, number) refuses the second one rather
 * than letting the series hold it twice.
 */
final readonly class NumberOnFinaliseListener
{
    public function __construct(
        private BillingIdGenerator $billingIdGenerator,
    ) {
    }

    /**
     * @param TransitionEvent<object> $event
     */
    #[AsEventListener(event: 'workflow.invoice.transition.accept')]
    public function onInvoiceFinalised(TransitionEvent $event): void
    {
        $invoice = $event->getSubject();

        if ($invoice instanceof Invoice && ! $invoice->isNumbered()) {
            $invoice->setInvoiceId($this->billingIdGenerator->generate($invoice, ['field' => 'invoiceId']));
        }
    }

    /**
     * @param TransitionEvent<object> $event
     */
    #[AsEventListener(event: 'workflow.credit_note.transition.issue')]
    public function onCreditNoteIssued(TransitionEvent $event): void
    {
        $creditNote = $event->getSubject();

        if ($creditNote instanceof CreditNote && ! $creditNote->isNumbered()) {
            $creditNote->setCreditNoteId($this->billingIdGenerator->generate($creditNote, ['field' => 'creditNoteId']));
        }
    }
}
