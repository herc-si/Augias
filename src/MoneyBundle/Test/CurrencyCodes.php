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

namespace Augias\MoneyBundle\Test;

use Augias\MoneyBundle\Currency\SupportedCurrencies;
use Faker\Generator;

/**
 * A currency a factory can hand out without arming a 500 further down.
 *
 * Faker's `currencyCode()` draws from a list wider than the one the money
 * layer can format, so a factory using it built a client that rendered on most
 * seeds and threw `UnknownCurrencyException` on others — inside a Twig
 * template, on one CI job out of fourteen, green on the rerun. Three
 * undiagnosed failures over three weeks came from this.
 *
 * @see \Augias\MoneyBundle\Currency\SupportedCurrencies
 */
final class CurrencyCodes
{
    public static function random(Generator $faker): string
    {
        /** @var string $code */
        $code = $faker->randomElement(new SupportedCurrencies()->codes());

        return $code;
    }
}
