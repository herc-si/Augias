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

namespace Augias\MoneyBundle\Formatter;

use Augias\MoneyBundle\Currency\CurrencyScale;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;
use Symfony\Component\Intl\Currencies;
use Symfony\Polyfill\Intl\Icu\Exception\MethodArgumentNotImplementedException;
use Symfony\Polyfill\Intl\Icu\Exception\MethodArgumentValueNotImplementedException;
use Throwable;
use function is_string;

/**
 * @see \Augias\MoneyBundle\Tests\Formatter\MoneyFormatterTest
 */
final class MoneyFormatter implements MoneyFormatterInterface
{
    private readonly string $locale;

    private readonly \Money\MoneyFormatter $formatter;

    private NumberFormatter $numberFormatter;

    private readonly ISOCurrencies $currencies;

    /**
     * @throws MethodArgumentNotImplementedException|MethodArgumentValueNotImplementedException
     */
    public function __construct(
        string $locale,
        private readonly SystemConfig $systemConfig
    ) {
        try {
            $this->numberFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        } catch (MethodArgumentValueNotImplementedException | MethodArgumentNotImplementedException) {
            $this->numberFormatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
        }

        $this->currencies = new ISOCurrencies();
        $this->formatter = new IntlMoneyFormatter($this->numberFormatter, $this->currencies);
        $this->locale = $locale;
    }

    public function format(Money $money): string
    {
        if ($this->currencies->contains($money->getCurrency())) {
            return $this->formatter->format($money);
        }

        // A code moneyphp does not know — a currency withdrawn from circulation,
        // on an incoming e-invoice — would throw inside the template and answer
        // 500. It degrades the way CurrencyScale stores it: two decimals, and
        // the code beside them for want of a symbol.
        $decimal = new NumberFormatter($this->numberFormatter->getLocale() ?: 'en', NumberFormatter::DECIMAL);
        $decimal->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, CurrencyScale::DEFAULT_SUBUNIT);
        $decimal->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, CurrencyScale::DEFAULT_SUBUNIT);

        return $decimal->format(BigDecimal::of($money->getAmount())->withPointMovedLeft(CurrencyScale::DEFAULT_SUBUNIT)->toFloat()) . ' ' . $money->getCurrency()->getCode();
    }

    /**
     * @throws Throwable
     */
    public function getCurrencySymbol(Currency | string | null $currency = null, bool $catch = false): string
    {
        try {
            return Currencies::getSymbol($this->getCurrency($currency), $this->locale);
        } catch (Throwable $e) {
            if ($catch) {
                return '';
            }

            throw $e;
        }
    }

    public function getThousandSeparator(): string
    {
        return $this->numberFormatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
    }

    public function getDecimalSeparator(): string
    {
        return $this->numberFormatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    }

    public function getPattern(): string
    {
        if (extension_loaded('intl')) {
            $pattern = explode(';', $this->numberFormatter->getPattern());

            // The number placeholder is matched rather than compared against a literal
            // "#,##0.00": the number of decimals in the pattern follows the locale, so a
            // zero-decimal locale yields "#,##0" and a three-decimal one "#,##0.000".
            $pattern = preg_replace('/[#,]+0(?:\.[0#]+)?/', '%v', str_replace('¤', '%s', $pattern[0]));

            // preg_replace returns the subject untouched when nothing matched, so an
            // unrecognised pattern would otherwise be handed back with its ICU digits intact.
            // Only accept a result that actually carries the amount placeholder.
            if (null !== $pattern && str_contains($pattern, '%v')) {
                return $pattern;
            }
        }

        return '%s%v';
    }

    /**
     * @throws MathException
     */
    public static function toFloat(BigNumber $amount): float
    {
        return $amount
            ->toBigDecimal()
            ->toFloat();
    }

    private function getCurrency(Currency | string | null $currency): string
    {
        if ($currency instanceof Currency) {
            return $currency->getCode();
        }

        if (is_string($currency)) {
            return $currency;
        }

        return $this->systemConfig->getCurrency()->getCode();
    }
}
