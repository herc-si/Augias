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

namespace Augias\CoreBundle\Billing;

use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\PaymentBundle\Repository\PaymentRepository;
use Augias\QuoteBundle\Entity\Quote;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;

/**
 * Populates {@see BaseInvoice}/{@see Quote} totals (subtotal, tax, grand total,
 * balance) by delegating tax and discount math to {@see TaxCalculatorInterface}.
 *
 * @see \Augias\CoreBundle\Tests\Billing\TotalCalculatorTest
 */
class TotalCalculator
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly TaxCalculatorInterface $taxCalculator,
    ) {
    }

    /**
     * @throws MathException
     */
    public function calculateTotals(BaseInvoice | Quote $entity): void
    {
        $this->updateTotal($entity);

        if ($entity instanceof Invoice) {
            $totalPaid = $this->paymentRepository->getTotalPaidForInvoice($entity);
            $total = $entity->getTotal();
            assert($total instanceof BigDecimal || $total instanceof BigInteger);

            $entity->setBalance($total->minus($totalPaid));
        }
    }

    /**
     * @throws MathException
     */
    private function updateTotal(BaseInvoice | Quote $entity): void
    {
        $result = $this->taxCalculator->calculate($entity);

        $subTotal = $result->subTotal;
        $tax = $result->getTotalTax();
        $total = $result->total;
        $withholding = $result->totalWithholding;

        // The discount is already out of the total, and out of the tax: it
        // comes off the net before the tax is charged, in the calculator.
        $entity->setBaseTotal($subTotal);
        $entity->setTax($tax);

        // Only invoices carry disbursements — a quote proposes, it advances
        // nothing — and Quote has no such total to set. See
        // Augias\QuoteBundle\Entity\Line::isDisbursement().
        if ($entity instanceof BaseInvoice) {
            $entity->setDisbursementTotal($result->disbursementTotal);
        }

        $entity->setTotal($total);
        $entity->setWithholdingAmount($withholding);
        $entity->setPayableAmount(BigDecimal::of($total)->minus($withholding));
    }
}
