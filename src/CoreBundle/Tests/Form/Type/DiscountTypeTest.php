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

namespace Augias\CoreBundle\Tests\Form\Type;

use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Form\Type\DiscountType;
use Augias\CoreBundle\Tests\FormTestCase;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigDecimal;
use Generator;
use Mockery as M;
use Money\Currency;
use Override;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\PreloadedExtension;

final class DiscountTypeTest extends FormTestCase
{
    /**
     * @return array<FormExtensionInterface>
     */
    #[Override]
    protected function getExtensions(): array
    {
        $systemConfig = M::mock(SystemConfig::class);

        $systemConfig
            ->shouldReceive('getCurrency')
            ->zeroOrMoreTimes()
            ->andReturn(new Currency('USD'));

        return [
            new PreloadedExtension([new DiscountType($systemConfig)], []),
        ];
    }

    public function testSubmit(): void
    {
        foreach ($this->discountProvider() as $discountItem) {
            $formData = [
                'type' => $discountItem[0],
                'value' => $discountItem[1],
            ];

            // A percentage is stored as typed — 15 means 15%. A money discount
            // is typed in major units and stored in minor ones, which is the
            // only reason this field scales anything at all.
            $value = BigDecimal::of($discountItem[1]);

            $object = new Discount();
            $object->setType($discountItem[0]);
            $object->setValue($discountItem[0] === Discount::TYPE_MONEY ? $value->multipliedBy(100) : $value);

            $this->assertFormData(DiscountType::class, $formData, $object);
        }
    }

    public function discountProvider(): Generator
    {
        yield [Discount::TYPE_PERCENTAGE, $this->faker->numberBetween(0, 100)];
        yield [Discount::TYPE_MONEY, $this->faker->numberBetween(0, 100)];
    }

    /**
     * An emptied value field means no discount, not a crash. The credit note
     * form adds this field blank the moment an invoice is picked, so the very
     * next re-render submitted an empty value and Discount::setValue(null)
     * threw a TypeError: a 500 on the credit note form.
     */
    public function testAnEmptyValueReadsAsNoDiscount(): void
    {
        foreach ([Discount::TYPE_PERCENTAGE, Discount::TYPE_MONEY] as $type) {
            $form = $this->factory->create(DiscountType::class);
            $form->submit(['type' => $type, 'value' => '']);

            self::assertTrue($form->isSynchronized());
            $discount = $form->getData();
            self::assertInstanceOf(Discount::class, $discount);
            self::assertTrue(BigDecimal::of((string) $discount->getValue())->isZero(), $type);
        }
    }

    /**
     * The display half of the money scaling: stored in minor units, shown in
     * major ones. It used to come from a view transformer on the value field,
     * which had no way of telling a money discount from a percentage and so
     * divided both.
     */
    public function testAMoneyDiscountIsShownInMajorUnits(): void
    {
        $discount = new Discount();
        $discount->setType(Discount::TYPE_MONEY);
        $discount->setValue(5000);

        $view = $this->factory->create(DiscountType::class, $discount)
            ->createView();

        self::assertSame('50.00', $view->children['value']->vars['value']);
    }

    /**
     * A percentage is shown exactly as it is stored — 15 means 15%, the same
     * figure the API and the MCP tools exchange.
     */
    public function testAPercentageIsShownAsStored(): void
    {
        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(15);

        $view = $this->factory->create(DiscountType::class, $discount)
            ->createView();

        self::assertSame('15', $view->children['value']->vars['value']);
    }
}
