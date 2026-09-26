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

namespace Augias\ApiBundle\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Augias\ApiBundle\DTO\RecordPaymentInput;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\PaymentBundle\Repository\PaymentMethodRepository;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Workflow\WorkflowInterface;

/** @implements ProcessorInterface<RecordPaymentInput, Payment> */
final readonly class RecordPaymentProcessor implements ProcessorInterface
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private PaymentMethodRepository $paymentMethodRepository,
        private ManagerRegistry $registry,
        private WorkflowInterface $invoiceStateMachine,
        private CashRegisterGateInterface $cashRegister,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Payment
    {
        assert($data instanceof RecordPaymentInput);

        $invoiceId = $uriVariables['invoiceId'] ?? null;

        $invoice = $this->invoiceRepository->findOneBy(['id' => $invoiceId]);

        if ($invoice === null) {
            throw new NotFoundHttpException(sprintf('Invoice "%s" not found.', $invoiceId));
        }

        $offlineMethod = $this->paymentMethodRepository->findOneBy(['factoryName' => PaymentMethod::FACTORY_OFFLINE]);

        if (! $offlineMethod instanceof PaymentMethod) {
            throw new ServiceUnavailableHttpException(null, 'Offline payment method is not configured.');
        }

        $client = $invoice->getClient();
        $invoiceCurrency = $client?->getCurrencyCode();

        if ($invoiceCurrency === null) {
            throw new UnprocessableEntityHttpException('Invoice has no resolvable currency.');
        }

        if ($data->currency !== $invoiceCurrency) {
            throw new UnprocessableEntityHttpException(
                sprintf('Payment currency "%s" does not match invoice currency "%s".', $data->currency, $invoiceCurrency)
            );
        }

        if ($this->cashRegister->refusesPaymentFrom($invoice->getCompany(), $client)) {
            throw new UnprocessableEntityHttpException('The company is VAT-registered and does not keep its books in Augias: the payment of a private customer cannot be recorded until an accounting regime is chosen (French tax code, art. 286, I, 3° bis).');
        }

        if (! $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_PAY)) {
            throw new UnprocessableEntityHttpException(
                sprintf('Pay transition cannot be applied to invoice in status "%s".', $invoice->getStatus()?->value ?? 'unknown')
            );
        }

        $payment = new Payment();
        $payment->setTotalAmount($data->amount);
        $payment->setCurrencyCode($data->currency);
        $payment->setReference($data->reference);
        $payment->setNotes($data->notes);
        $payment->setMethod($offlineMethod);
        $payment->setInvoice($invoice);
        if ($client !== null) {
            $payment->setClient($client);
        }

        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted(CarbonImmutable::now());
        $payment->setCompany($invoice->getCompany());

        $em = $this->registry->getManager();
        $em->persist($payment);

        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_PAY);

        $em->flush();

        return $payment;
    }
}
