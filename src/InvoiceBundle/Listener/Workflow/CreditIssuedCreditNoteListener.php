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

use Augias\ClientBundle\Repository\CreditRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Brick\Math\Exception\MathException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Puts an issued credit note onto the client's balance.
 *
 * Issuing is the moment the client is owed the money, so it is the moment the
 * balance has to say so. {@see \Augias\InvoiceBundle\Service\CreditNoteAllocator}
 * takes it back off as the credit is used, which leaves the balance holding
 * exactly what is still outstanding.
 *
 * @see \Augias\InvoiceBundle\Tests\Listener\Workflow\CreditIssuedCreditNoteListenerTest
 */
final readonly class CreditIssuedCreditNoteListener
{
    public function __construct(
        private CreditRepository $credits,
    ) {
    }

    /**
     * @param CompletedEvent<CreditNote> $event
     * @throws MathException
     */
    #[AsEventListener('workflow.credit_note.completed.' . CreditNoteGraph::TRANSITION_ISSUE)]
    public function onIssued(CompletedEvent $event): void
    {
        $creditNote = $event->getSubject();

        if (! $creditNote instanceof CreditNote) {
            return;
        }

        $this->credits->addCredit($creditNote->getClient(), $creditNote->getTotal());
    }
}
