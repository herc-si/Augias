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

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Repository\CreditNoteAllocationRepository;
use Augias\PaymentBundle\Repository\PaymentRepository;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * What an invoice is still owed once its payments and the credit notes set
 * against it are counted.
 *
 * A credit note set against an invoice used to come off the client's credit
 * and nowhere else: the invoice kept its full balance, went overdue and was
 * chased for money the client no longer owed.
 */
final readonly class InvoiceSettlement
{
    public function __construct(
        private PaymentRepository $payments,
        private CreditNoteAllocationRepository $allocations,
        private WorkflowInterface $invoiceStateMachine,
    ) {
    }

    /**
     * @throws MathException
     */
    public function balance(Invoice $invoice): BigDecimal
    {
        return $invoice->getTotal()->toBigDecimal()
            ->minus($this->payments->getTotalPaidForInvoice($invoice))
            ->minus($this->allocations->offsetTotalForInvoice($invoice));
    }

    /**
     * Brings the balance up to date and, once nothing is owed, closes the
     * invoice: paid when any money came in, credited when credit notes alone
     * settled it.
     *
     * @throws MathException
     */
    public function refresh(Invoice $invoice): void
    {
        $balance = $this->balance($invoice);

        $invoice->setBalance($balance->isNegative() ? BigDecimal::zero() : $balance);

        if ($balance->isPositive()) {
            return;
        }

        $paid = $this->payments->getTotalPaidForInvoice($invoice);
        $transition = BigNumber::of($paid)->isZero() ? Graph::TRANSITION_CREDIT : Graph::TRANSITION_PAY;

        if ($this->invoiceStateMachine->can($invoice, $transition)) {
            $this->invoiceStateMachine->apply($invoice, $transition);
        }
    }
}
