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

namespace Augias\MoneyBundle\Tests;

use Augias\CoreBundle\Entity\Discount;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\MoneyBundle\Calculator;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    /**
     * @throws MathException
     */
    public function testCalculateDiscount(): void
    {
        $calculator = new Calculator();
        $entity = new Invoice();
        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(10);

        $entity->setDiscount($discount);
        $entity->setBaseTotal(20000);

        self::assertEquals(BigDecimal::of(2000), $calculator->calculateDiscount($entity));
    }

    /**
     * A percentage is applied exactly as stored. The scale used to be guessed
     * from the magnitude — anything above 100 was divided by a hundred — to
     * absorb the form filing 15% as 1500. Nothing writes a scaled percentage
     * any more, and the guess made a 150% discount read as 1.5%. Read as
     * 150%, it takes the whole net and no more: a discount does not leave the
     * client owed money.
     *
     * @throws MathException
     */
    public function testCalculateDiscountAboveOneHundredPercent(): void
    {
        $calculator = new Calculator();
        $entity = new Invoice();
        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(150);

        $entity->setDiscount($discount);
        $entity->setBaseTotal(20000);

        self::assertEquals(BigDecimal::of(20000), $calculator->calculateDiscount($entity));
    }

    /**
     * The type is nullable and defaults to a percentage, so a discount that
     * never had one set must still apply as one. It used to fall through to the
     * money branch and read as zero.
     *
     * @throws MathException
     */
    public function testCalculateDiscountWithNoTypeSet(): void
    {
        $calculator = new Calculator();
        $entity = new Invoice();
        $discount = new Discount();
        $discount->setType(null);
        $discount->setValue(15);

        $entity->setDiscount($discount);
        $entity->setBaseTotal(20000);

        self::assertEquals(BigDecimal::of(3000), $calculator->calculateDiscount($entity));
    }

    /**
     * @throws MathException
     */
    public function testCalculateDiscountPercentage(): void
    {
        $calculator = new Calculator();
        $entity = new Invoice();
        $discount = new Discount();
        $discount->setType(Discount::TYPE_MONEY);
        $discount->setValue(35);

        $entity->setDiscount($discount);
        $entity->setBaseTotal(200);

        self::assertEquals(BigDecimal::of(35), $calculator->calculateDiscount($entity));
    }

    /**
     * @throws MathException
     */
    public function testCalculatePercentage(): void
    {
        $calculator = new Calculator();
        self::assertSame(0.0, $calculator->calculatePercentage(100));
        self::assertSame(24.0, $calculator->calculatePercentage(200, 12));
        self::assertSame(40.0, $calculator->calculatePercentage(200, 20));
    }
}
