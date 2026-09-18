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

namespace Augias\MoneyBundle\Currency;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Symfony\Component\Intl\Currencies;
use function array_fill_keys;
use function array_key_exists;

/**
 * The currencies this application can carry from storage to screen.
 *
 * Two libraries have to agree on a code before it is safe to store, and they
 * disagree about 129 of them:
 *
 *  - moneyphp formats through `ISOCurrencies`, which lists only currencies in
 *    circulation. Asking it about one that is not — `CUC`, the Cuban
 *    convertible peso, withdrawn in 2021 — throws `UnknownCurrencyException`,
 *    and it throws it while a Twig template is rendering an amount;
 *  - Symfony's Intl knows `CUC`, along with the French franc and the Zimbabwe
 *    dollar, because its list carries history rather than circulation.
 *
 * A code in the second list but not the first is storable and unformattable:
 * the client record saves, and every page that shows an amount for that client
 * answers 500. `CurrencyType` never offered one, so the form was safe by
 * accident — but the property behind it accepted any three characters, which
 * is what the API and the import paths go through.
 *
 * Membership is the intersection, in the order `ISOCurrencies` iterates, so
 * that a code is storable exactly when it is both formattable and nameable.
 *
 * @see \Augias\MoneyBundle\Tests\Currency\SupportedCurrenciesTest
 */
final class SupportedCurrencies
{
    /**
     * @var list<string>|null
     */
    private ?array $codes = null;

    /**
     * @var array<string, true>|null
     */
    private ?array $index = null;

    public function contains(Currency | string $currency): bool
    {
        $code = $currency instanceof Currency ? $currency->getCode() : $currency;

        return array_key_exists($code, $this->index());
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        if (null === $this->codes) {
            $codes = [];

            foreach (new ISOCurrencies() as $currency) {
                $code = $currency->getCode();

                if ('' !== $code && Currencies::exists($code)) {
                    $codes[] = $code;
                }
            }

            $this->codes = $codes;
        }

        return $this->codes;
    }

    /**
     * @return array<string, true>
     */
    private function index(): array
    {
        return $this->index ??= array_fill_keys($this->codes(), true);
    }
}
