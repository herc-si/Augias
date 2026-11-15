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

namespace Augias\DashboardBundle\Tests\Twig;

use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use function preg_replace;
use function strip_tags;
use function trim;

/**
 * The dashboard writes an amount the way every other screen does: the
 * currency symbol, on the side the locale puts it. It used to write the ISO
 * code in front, "EUR 70 667,71" beside the "70 667,71 €" of the lists.
 */
final class MoneyMacroTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testTheAmountReadsLikeTheRestOfTheApplication(): void
    {
        $macro = $this->render("{% import '@AugiasDashboard/money.html.twig' as money %}{{ money.money(7066771, 'EUR') }}");
        $filter = $this->render("{{ 7066771|formatCurrency('EUR') }}");

        self::assertSame($this->squash($filter), $this->squash(strip_tags($macro)));
        self::assertStringNotContainsString('EUR', $macro);
        self::assertStringContainsString('money-currency--symbol', $macro);
    }

    public function testACurrencyWithoutASymbolFallsBackToItsCode(): void
    {
        $macro = $this->render("{% import '@AugiasDashboard/money.html.twig' as money %}{{ money.money(1000, 'CUC') }}");

        self::assertStringContainsString('CUC', $macro);
    }

    private function render(string $source): string
    {
        $twig = self::getContainer()->get(Environment::class);

        return $twig->createTemplate($source)->render();
    }

    /**
     * The two spellings may differ by the kind of space between the amount and its symbol.
     */
    private function squash(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text));
    }
}
