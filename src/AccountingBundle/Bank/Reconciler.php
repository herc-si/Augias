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

namespace Augias\AccountingBundle\Bank;

use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\AccountingBundle\Repository\BankTransactionRepository;
use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Entity\BillPayment;
use Augias\BillBundle\Enum\BillPaymentMethod;
use Augias\BillBundle\Manager\BillPaymentManager;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\PaymentBundle\Event\PaymentCompleteEvent;
use Augias\PaymentBundle\Event\PaymentEvents;
use Augias\PaymentBundle\Repository\PaymentMethodRepository;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use function in_array;

/**
 * Ties a bank line to what it is the trace of — and, when that is an invoice
 * or a bill still owed, records the payment the line proves.
 *
 * A recorded payment goes the way the payment screen takes it: an offline
 * bank transfer dated the day the bank booked it, then the payment-complete
 * event that updates the balance and marks the document paid. The books are
 * written from that payment, as from any other.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\ReconciliationTest
 */
final readonly class Reconciler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BankTransactionRepository $transactions,
        private PaymentMethodRepository $paymentMethods,
        private BillPaymentManager $billPayments,
        private EventDispatcherInterface $eventDispatcher,
        private CashRegisterGateInterface $cashRegister,
    ) {
    }

    /**
     * @throws ReconciliationRefused
     */
    public function match(BankTransaction $line, string $kind, string $id): void
    {
        match ($kind) {
            Suggestion::PAYMENT => $this->linkPayment($line, $this->find(Payment::class, $id)),
            Suggestion::INVOICE => $this->payInvoice($line, $this->find(Invoice::class, $id)),
            Suggestion::BILL_PAYMENT => $this->linkBillPayment($line, $this->find(BillPayment::class, $id)),
            Suggestion::BILL => $this->payBill($line, $this->find(Bill::class, $id)),
            default => throw new ReconciliationRefused('unknown_kind'),
        };
    }

    public function ignore(BankTransaction $line): void
    {
        $line->ignore();
        $this->entityManager->flush();
    }

    public function reopen(BankTransaction $line): void
    {
        $line->reopen();
        $this->entityManager->flush();
    }

    private function linkPayment(BankTransaction $line, Payment $payment): void
    {
        if (! $line->isCredit() || ! BigInteger::of((string) $payment->getTotalAmount())->isEqualTo($line->getAmount())) {
            throw new ReconciliationRefused('amount_mismatch');
        }

        if ($this->transactions->isPaymentMatched($payment)) {
            throw new ReconciliationRefused('already_matched');
        }

        $line->matchPayment($payment);
        $this->entityManager->flush();
    }

    private function linkBillPayment(BankTransaction $line, BillPayment $payment): void
    {
        if ($line->isCredit() || ! $payment->getAmount()->toBigInteger()->isEqualTo($line->getAmount()->abs())) {
            throw new ReconciliationRefused('amount_mismatch');
        }

        if ($this->transactions->isBillPaymentMatched($payment)) {
            throw new ReconciliationRefused('already_matched');
        }

        $line->matchBillPayment($payment);
        $this->entityManager->flush();
    }

    private function payInvoice(BankTransaction $line, Invoice $invoice): void
    {
        $client = $invoice->getClient();

        if (! $line->isCredit() || ! in_array($invoice->getStatus(), [InvoiceStatus::Pending, InvoiceStatus::Overdue], true)) {
            throw new ReconciliationRefused('not_payable');
        }

        if ($line->getAmount()->isGreaterThan($invoice->getBalance()->toBigInteger())) {
            throw new ReconciliationRefused('exceeds_balance');
        }

        if ($client?->getCurrencyCode() !== $line->getBankAccount()->getCurrencyCode()) {
            throw new ReconciliationRefused('currency_mismatch');
        }

        // A private customer's payment is kept only where the books are.
        if ($this->cashRegister->refusesPaymentFrom($invoice->getCompany(), $client)) {
            throw new ReconciliationRefused('books_required');
        }

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setClient($client);
        $payment->setMethod($this->bankTransfer());
        $payment->setTotalAmount($line->getAmount()->toInt());
        $payment->setCurrencyCode($line->getBankAccount()->getCurrencyCode());
        $payment->setDescription('');
        $payment->setNumber($invoice->getId()?->toString());
        $payment->setReference($line->getReference() ?? $line->getLabel());
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted($line->getBookingDate());
        $payment->setCompany($invoice->getCompany());
        $invoice->addPayment($payment);

        $line->matchPayment($payment);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        // Balance, paid status and the notification, as for a payment typed in.
        $this->eventDispatcher->dispatch(new PaymentCompleteEvent($payment), PaymentEvents::PAYMENT_COMPLETE);
    }

    private function payBill(BankTransaction $line, Bill $bill): void
    {
        $amount = $line->getAmount()->abs();

        if ($line->isCredit() || $bill->getCurrencyCode() !== $line->getBankAccount()->getCurrencyCode()) {
            throw new ReconciliationRefused('not_payable');
        }

        if ($amount->isGreaterThan(BigInteger::of($bill->getBalance()->getAmount()))) {
            throw new ReconciliationRefused('exceeds_balance');
        }

        $payment = $this->billPayments->recordPayment($bill, $amount, $line->getBookingDate(), BillPaymentMethod::BankTransfer, $line->getReference() ?? $line->getLabel());

        $line->matchBillPayment($payment);
        $this->entityManager->flush();
    }

    private function bankTransfer(): PaymentMethod
    {
        $method = $this->paymentMethods->findOneBy(['gatewayName' => 'bank_transfer', 'factoryName' => PaymentMethod::FACTORY_OFFLINE])
            ?? $this->paymentMethods->findOneBy(['factoryName' => PaymentMethod::FACTORY_OFFLINE, 'gatewayName' => 'cash']);

        if (! $method instanceof PaymentMethod) {
            throw new ReconciliationRefused('no_offline_method');
        }

        return $method;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, string $id): object
    {
        $entity = $this->entityManager->find($class, $id);

        if (null === $entity) {
            throw new ReconciliationRefused('not_found');
        }

        return $entity;
    }
}
