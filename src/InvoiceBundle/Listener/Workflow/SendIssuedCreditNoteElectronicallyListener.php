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

use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * A credit note to a business client goes through the electronic invoicing
 * platform, like an invoice: it is an invoice that corrects another (CGI
 * art. 289). Sent as soon as it is issued, whichever way it was issued.
 *
 * A failure does not undo the issue: the credit note page shows the
 * submission and offers to send it again.
 *
 * Last of the issue listeners: the number, and any offset against the
 * credited invoice, are in place by then.
 */
final readonly class SendIssuedCreditNoteElectronicallyListener
{
    public function __construct(
        private ElectronicInvoiceManagerInterface $electronicInvoiceManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param CompletedEvent<CreditNote> $event
     */
    #[AsEventListener('workflow.credit_note.completed.' . CreditNoteGraph::TRANSITION_ISSUE, priority: -20)]
    public function onIssued(CompletedEvent $event): void
    {
        $creditNote = $event->getSubject();

        if (! $creditNote instanceof CreditNote || ! $this->electronicInvoiceManager->isEligible($creditNote)) {
            return;
        }

        try {
            $this->electronicInvoiceManager->send($creditNote);
        } catch (LogicException $e) {
            $this->logger->error('Failed to send the credit note electronically: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
