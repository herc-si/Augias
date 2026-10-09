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

namespace Augias\InvoiceBundle\Tests\Model;

use Augias\CoreBundle\Entity\Discount;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Model\MirroredDiscount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A credit note gives back what was invoiced: the invoice's discount comes
 * with it, shared out over the lines credited when it was a fixed amount.
 */
#[CoversClass(MirroredDiscount::class)]
final class MirroredDiscountTest extends TestCase
{
    public function testWithNoInvoiceThereIsNoDiscount(): void
    {
        $discount = MirroredDiscount::for(null, [$this->creditLine(10_000)]);

        self::assertTrue($discount->amountOn(\Brick\Math\BigDecimal::of(10_000))->isZero());
    }

    public function testAPercentageCarriesOverAsItIs(): void
    {
        $invoice = $this->invoice($this->percentage(10), [10_000, 5_000]);

        $discount = MirroredDiscount::for($invoice, [$this->creditLine(5_000)]);

        self::assertSame(Discount::TYPE_PERCENTAGE, $discount->getType());
        self::assertSame(10.0, $discount->getValuePercentage());
    }

    public function testAFixedAmountIsTakenWholeWhenEverythingIsCredited(): void
    {
        $invoice = $this->invoice($this->amount(5_000), [10_000, 5_000]);

        $discount = MirroredDiscount::for($invoice, [$this->creditLine(10_000), $this->creditLine(5_000)]);

        self::assertSame('5000', (string) $discount->getValueMoney());
    }

    public function testAFixedAmountIsSharedOutOverAPartialCredit(): void
    {
        $invoice = $this->invoice($this->amount(5_000), [10_000, 10_000]);

        $discount = MirroredDiscount::for($invoice, [$this->creditLine(10_000)]);

        self::assertSame('2500', (string) $discount->getValueMoney());
    }

    /**
     * Disbursements are passed on at cost: they take no share of a discount.
     */
    public function testDisbursementsTakeNoShare(): void
    {
        $invoice = $this->invoice($this->amount(1_000), [10_000]);
        $invoice->addLine(new Line()->setDescription('Frais de greffe')->setPrice(5_000)->setQty(1)->setDisbursement(true)->updateTotal());

        $discount = MirroredDiscount::for($invoice, [$this->creditLine(10_000)]);

        self::assertSame('1000', (string) $discount->getValueMoney());
    }

    /**
     * @param list<int> $prices
     */
    private function invoice(Discount $discount, array $prices): Invoice
    {
        $invoice = new Invoice();
        $invoice->setDiscount($discount);

        foreach ($prices as $price) {
            $invoice->addLine(new Line()->setDescription('Service')->setPrice($price)->setQty(1)->updateTotal());
        }

        return $invoice;
    }

    private function creditLine(int $price): CreditNoteLine
    {
        return new CreditNoteLine()->setDescription('Service')->setPrice($price)->setQty(1)->updateTotal();
    }

    private function percentage(float $rate): Discount
    {
        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue($rate);

        return $discount;
    }

    private function amount(int $minor): Discount
    {
        $discount = new Discount();
        $discount->setType(Discount::TYPE_MONEY);
        $discount->setValue($minor);

        return $discount;
    }
}
