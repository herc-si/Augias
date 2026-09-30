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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function strtoupper;
use function trim;

/**
 * Which currencies a company and its clients may be given, and which one
 * comes first.
 *
 * AUGIAS_DEFAULT_CURRENCY is the one a new company starts with: the euro,
 * Augias being made for French businesses first. AUGIAS_CURRENCIES, a
 * comma-separated list, narrows the choice where a deployment sells to a
 * given region — the hosted service lists European currencies only. Empty,
 * every supported currency is offered.
 *
 * Only the choice is narrowed. What is already stored keeps being read, and a
 * supplier's bill may be in any currency: a French business can still buy
 * from abroad.
 *
 * @see \Augias\MoneyBundle\Tests\Currency\CurrencyPolicyTest
 */
final readonly class CurrencyPolicy
{
    /** @var list<string> */
    private array $offered;

    private string $default;

    public function __construct(
        private SupportedCurrencies $supported,
        #[Autowire(env: 'AUGIAS_CURRENCIES')]
        string $currencies = '',
        #[Autowire(env: 'AUGIAS_DEFAULT_CURRENCY')]
        string $default = 'EUR',
    ) {
        $listed = array_values(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', $currencies),
        ), fn (string $code): bool => $code !== '' && $this->supported->contains($code)));

        $this->offered = $listed === [] ? $this->supported->codes() : $listed;

        $default = strtoupper(trim($default));
        $this->default = in_array($default, $this->offered, true) ? $default : $this->offered[0];
    }

    /**
     * @return list<string> the currencies offered, the default first
     */
    public function offeredCodes(): array
    {
        return [$this->default, ...array_values(array_filter($this->offered, fn (string $code): bool => $code !== $this->default))];
    }

    public function defaultCode(): string
    {
        return $this->default;
    }
}
