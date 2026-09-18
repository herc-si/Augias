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

use Augias\MoneyBundle\Currency\SupportedCurrencies;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Currencies;

#[CoversClass(SupportedCurrencies::class)]
final class SupportedCurrenciesTest extends TestCase
{
    public function testACurrencyOutOfCirculationIsNotSupported(): void
    {
        // CUC, the Cuban convertible peso, was withdrawn in 2021. Intl still
        // knows it; the money layer does not. It is the code that took three
        // CI runs down before anyone could read why.
        self::assertTrue(Currencies::exists('CUC'));
        self::assertFalse(new SupportedCurrencies()->contains('CUC'));
    }

    public function testEverydayCurrenciesAreSupported(): void
    {
        $supported = new SupportedCurrencies();

        foreach (['EUR', 'USD', 'GBP', 'ZAR', 'JPY'] as $code) {
            self::assertTrue($supported->contains($code), $code . ' should be supported');
        }
    }

    public function testACurrencyObjectIsAcceptedTheSameWayAsACode(): void
    {
        $supported = new SupportedCurrencies();

        self::assertTrue($supported->contains(new Currency('EUR')));
        self::assertFalse($supported->contains(new Currency('CUC')));
    }

    public function testNonsenseIsNotSupported(): void
    {
        self::assertFalse(new SupportedCurrencies()->contains('XYZ'));
        self::assertFalse(new SupportedCurrencies()->contains(''));
    }

    /**
     * The promise the list makes, stated as a test: nothing it contains can
     * throw while an amount is being rendered.
     */
    public function testEverySupportedCurrencyCanBeFormattedAndNamed(): void
    {
        $formatter = new IntlMoneyFormatter(
            new NumberFormatter('en', NumberFormatter::CURRENCY),
            new ISOCurrencies(),
        );

        $codes = new SupportedCurrencies()->codes();

        self::assertNotEmpty($codes);

        foreach ($codes as $code) {
            $formatter->format(new Money(1000, new Currency($code)));
            Currencies::getName($code);
            Currencies::getSymbol($code);
        }
    }
}
