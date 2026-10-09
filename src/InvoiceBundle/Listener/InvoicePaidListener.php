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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Credit;
use Augias\ClientBundle\Repository\CreditRepository;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Repository\PaymentRepository;
use Brick\Math\Exception\MathException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\Event;

class InvoicePaidListener implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.invoice.entered.paid' => 'onInvoicePaid',
        ];
    }

    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    /**
     * @template TSubject of object
     *
     * @param Event<TSubject> $event
     *
     * @throws MathException
     */
    public function onInvoicePaid(Event $event): void
    {
        /** @var Invoice $invoice */
        $invoice = $event->getSubject();

        $em = $this->registry->getManager();

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $em->getRepository(Payment::class);

        $em->persist($invoice);

        // Credit notes set against the invoice count as settled: only what was
        // paid beyond the rest is a real overpayment.
        $totalPaid = $paymentRepository->getTotalPaidForInvoice($invoice)
            ->toBigDecimal()
            ->plus($em->getRepository(CreditNoteAllocation::class)->offsetTotalForInvoice($invoice));

        if ($totalPaid->isGreaterThan($invoice->getTotal())) {
            /** @var Client $client */
            $client = $invoice->getClient();

            /** @var CreditRepository $creditRepository */
            $creditRepository = $em->getRepository(Credit::class);
            $creditRepository->addCredit($client, $totalPaid->minus($invoice->getTotal()));
        }

        $em->flush();
    }
}
