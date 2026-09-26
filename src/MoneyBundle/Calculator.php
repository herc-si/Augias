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

namespace Augias\MoneyBundle;

use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\MoneyBundle\Formatter\MoneyFormatter;
use Augias\QuoteBundle\Entity\Quote;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * @see \Augias\MoneyBundle\Tests\CalculatorTest
 */
final class Calculator
{
    /**
     * @throws MathException
     */
    public function calculateDiscount(Quote | BaseInvoice $entity): BigNumber
    {
        // Off the tax-exclusive fees, the way the tax calculator takes it:
        // the discount lowers the base the tax is charged on.
        return $entity->getDiscount()->amountOn($entity->getBaseTotal());
    }

    /**
     * Takes a plain percentage: 12 means 12%. Also exposed as the `percentage`
     * Twig filter.
     *
     * @throws MathException
     */
    public function calculatePercentage(BigNumber | int | string $amount, float $percentage = 0.0): float
    {
        return MoneyFormatter::toFloat(BigNumber::of($amount)->toBigDecimal()->multipliedBy(BigDecimal::of((string) $percentage)->dividedBy(100, 10, RoundingMode::HalfEven)));
    }
}
