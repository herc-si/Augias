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

namespace Augias\BillBundle\Listener;

use Augias\BillBundle\Entity\Bill;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * A purchase received electronically has a supplier on the other end, who
 * hears about it only through an answer: it is cancelled by refusing the
 * invoice — {@see CancelBillOnRefusalListener} — not by hand, and once
 * refused it stays cancelled. A refusal is final.
 */
final readonly class ElectronicBillGuardListener
{
    /**
     * @param GuardEvent<Bill> $event
     */
    #[AsEventListener('workflow.bill.guard.cancel')]
    public function onCancel(GuardEvent $event): void
    {
        $receipt = $event->getSubject()->getElectronicInvoiceReceipt();

        if (null !== $receipt && ReceiptResponse::Refused !== $receipt->getResponse()) {
            $event->addTransitionBlocker(new TransitionBlocker('Received electronically: refuse it to the supplier instead.', 'refuse_instead'));
        }
    }

    /**
     * @param GuardEvent<Bill> $event
     */
    #[AsEventListener('workflow.bill.guard.reopen')]
    #[AsEventListener('workflow.bill.guard.edit')]
    public function onReopen(GuardEvent $event): void
    {
        $bill = $event->getSubject();

        if (ReceiptResponse::Refused === $bill->getElectronicInvoiceReceipt()?->getResponse()) {
            $event->addTransitionBlocker(new TransitionBlocker('Refused to the supplier: a refusal is final.', 'refused'));
        }
    }
}
