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
use Augias\BillBundle\Model\Graph;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Event\ElectronicInvoiceReceiptAnsweredEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A received invoice refused to its supplier is owed by no one: the purchase
 * it became is cancelled — then, and only then. Cancelling it by hand would
 * leave the supplier thinking it due; {@see ElectronicBillGuardListener}
 * keeps the button for the refusal.
 */
#[AsEventListener(event: ElectronicInvoiceReceiptAnsweredEvent::class)]
final readonly class CancelBillOnRefusalListener
{
    public function __construct(
        private BillRepository $billRepository,
        private WorkflowInterface $billStateMachine,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ElectronicInvoiceReceiptAnsweredEvent $event): void
    {
        if (ReceiptResponse::Refused !== $event->response) {
            return;
        }

        $bill = $this->billRepository->findOneBy(['electronicInvoiceReceipt' => $event->receipt]);

        if (! $bill instanceof Bill || ! $this->billStateMachine->can($bill, Graph::TRANSITION_CANCEL)) {
            return;
        }

        $this->billStateMachine->apply($bill, Graph::TRANSITION_CANCEL);
        $this->entityManager->flush();
    }
}
