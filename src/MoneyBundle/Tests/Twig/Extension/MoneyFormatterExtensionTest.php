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

namespace Augias\MoneyBundle\Tests\Twig\Extension;

use Augias\MoneyBundle\Formatter\MoneyFormatter;
use Augias\MoneyBundle\Twig\Extension\MoneyFormatterExtension;
use Augias\SettingsBundle\SystemConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Money\Currency;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class MoneyFormatterExtensionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testGetFunctions(): void
    {
        $systemConfig = $this->createStub(SystemConfig::class);

        $moneyFormatter = new MoneyFormatter('en_US', $systemConfig);
        $extension = new MoneyFormatterExtension($moneyFormatter, $systemConfig);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);

        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('currencyFormatter', $functions[0]->getName());
        self::assertSame($moneyFormatter, call_user_func($functions[0]->getCallable()));
    }

    public function testGetFilters(): void
    {
        $systemConfig = $this->createMock(SystemConfig::class);

        $systemConfig
            ->expects(self::once())
            ->method('getCurrency')
            ->willReturn(new Currency('USD'));

        $moneyFormatter = new MoneyFormatter('en_US', $systemConfig);
        $extension = new MoneyFormatterExtension($moneyFormatter, $systemConfig);

        $filters = $extension->getFilters();

        self::assertCount(2, $filters);

        self::assertInstanceOf(TwigFilter::class, $filters[0]);
        self::assertSame('formatCurrency', $filters[0]->getName());
        self::assertSame('$12.00', call_user_func($filters[0]->getCallable(), 1200));

        self::assertInstanceOf(TwigFilter::class, $filters[1]);
        self::assertSame('formatCurrencyAmount', $filters[1]->getName());
        self::assertSame('12.00', call_user_func($filters[1]->getCallable(), 1200, 'USD'));
    }

    /**
     * `CUC`, withdrawn in 2021, is unknown to moneyphp and may still come in
     * on a supplier's e-invoice: shown, not a 500.
     */
    public function testACurrencyOutOfCirculationIsShownRatherThanFailing(): void
    {
        $systemConfig = $this->createStub(SystemConfig::class);
        $extension = new MoneyFormatterExtension(new MoneyFormatter('en_US', $systemConfig), $systemConfig);

        $filters = $extension->getFilters();

        self::assertSame('1,234.56 CUC', call_user_func($filters[0]->getCallable(), 123456, 'CUC'));
        self::assertSame('1,234.56', call_user_func($filters[1]->getCallable(), 123456, 'CUC'));
    }
}
