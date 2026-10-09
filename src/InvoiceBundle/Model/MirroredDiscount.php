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

namespace Augias\InvoiceBundle\Model;

use Augias\CoreBundle\Entity\Discount;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * The discount a credit note carries: not a new reduction, the one the credited
 * invoice already had, so that the credit note gives back what was actually
 * invoiced rather than the undiscounted price.
 *
 * A percentage carries over as it is: it applies to whatever lines are credited.
 * A fixed amount is shared out in proportion to the lines credited: crediting
 * half of an invoice that had 50 off takes 25 off, and crediting all of it
 * takes exactly 50. It is never typed on the credit note, so it cannot drift
 * from the invoice.
 */
final class MirroredDiscount
{
    /**
     * @param iterable<Line> $creditedLines
     *
     * @throws MathException
     */
    public static function for(?Invoice $invoice, iterable $creditedLines): Discount
    {
        $discount = new Discount();

        if (! $invoice instanceof Invoice) {
            return $discount;
        }

        $original = $invoice->getDiscount();

        if (Discount::TYPE_MONEY !== $original->getType()) {
            $discount->setType(Discount::TYPE_PERCENTAGE);
            $discount->setValue($original->getValuePercentage() ?? 0);

            return $discount;
        }

        $invoiced = self::net($invoice->getLines());
        $credited = self::net($creditedLines);

        $discount->setType(Discount::TYPE_MONEY);

        if (! $invoiced->isPositive()) {
            $discount->setValue(0);

            return $discount;
        }

        $share = $original->amountOn($invoiced)
            ->multipliedBy($credited)
            ->dividedBy($invoiced, 0, RoundingMode::HalfUp);

        $discount->setValue($share);

        return $discount;
    }

    /**
     * What a discount applies to: the lines sold, never the disbursements,
     * which are passed on at cost.
     *
     * @param iterable<Line> $lines
     */
    private static function net(iterable $lines): BigDecimal
    {
        $net = BigDecimal::zero();

        foreach ($lines as $line) {
            if ($line->isDisbursement()) {
                continue;
            }

            $net = $net->plus($line->getPrice()->toBigDecimal()->multipliedBy($line->getQty()->toBigDecimal()));
        }

        return $net;
    }
}
