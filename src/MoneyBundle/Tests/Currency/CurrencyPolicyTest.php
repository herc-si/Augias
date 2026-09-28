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

namespace Augias\MoneyBundle\Tests\Currency;

use Augias\MoneyBundle\Currency\CurrencyPolicy;
use Augias\MoneyBundle\Currency\SupportedCurrencies;
use Augias\MoneyBundle\Form\Type\CurrencyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use function array_values;
use function count;

#[CoversClass(CurrencyPolicy::class)]
#[CoversClass(CurrencyType::class)]
final class CurrencyPolicyTest extends TestCase
{
    public function testEverySupportedCurrencyIsOfferedWhenNoneIsListedWithTheEuroFirst(): void
    {
        $policy = new CurrencyPolicy(new SupportedCurrencies());

        self::assertSame('EUR', $policy->defaultCode());
        self::assertSame('EUR', $policy->offeredCodes()[0]);
        self::assertContains('USD', $policy->offeredCodes());
        self::assertCount(count(new SupportedCurrencies()->codes()), $policy->offeredCodes());
    }

    public function testAListNarrowsTheChoiceAndDropsWhatCannotBeStored(): void
    {
        // CUC is withdrawn: storable nowhere, so never offered.
        $policy = new CurrencyPolicy(new SupportedCurrencies(), ' gbp, EUR ,CHF,CUC,', 'EUR');

        self::assertSame(['EUR', 'GBP', 'CHF'], $policy->offeredCodes());
    }

    public function testADefaultOutsideTheListGivesWayToTheFirstListed(): void
    {
        $policy = new CurrencyPolicy(new SupportedCurrencies(), 'CHF,EUR', 'USD');

        self::assertSame('CHF', $policy->defaultCode());
    }

    public function testThePickerOffersTheListUnlessToldToOfferEverything(): void
    {
        $policy = new CurrencyPolicy(new SupportedCurrencies(), 'EUR,CHF');
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([new CurrencyType('fr', new SupportedCurrencies(), $policy)], []))
            ->getFormFactory();

        $narrowed = $factory->create(CurrencyType::class)->getConfig()->getOption('choices');
        $everything = $factory->create(CurrencyType::class, options: ['restricted' => false])->getConfig()->getOption('choices');

        self::assertSame(['EUR', 'CHF'], array_values($narrowed));
        self::assertContains('USD', $everything);
    }
}
