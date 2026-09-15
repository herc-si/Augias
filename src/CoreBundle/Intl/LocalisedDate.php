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

namespace Augias\CoreBundle\Intl;

use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Locale;
use function array_key_exists;

/**
 * Dates rendered by PHP rather than by a template.
 *
 * `DateTimeInterface::format()` takes a format string that spells out month and
 * day names in English, whatever language the page is in. Everything here goes
 * through ICU instead, so the reader's locale decides the names, the order and
 * the separators.
 *
 * @see \Augias\CoreBundle\Tests\Intl\LocalisedDateTest
 */
final class LocalisedDate
{
    private const array WIDTHS = [
        'none' => IntlDateFormatter::NONE,
        'short' => IntlDateFormatter::SHORT,
        'medium' => IntlDateFormatter::MEDIUM,
        'long' => IntlDateFormatter::LONG,
        'full' => IntlDateFormatter::FULL,
    ];

    /**
     * @param string $date One of none, short, medium, long, full
     * @param string $time Same, for the time part
     */
    public function format(DateTimeInterface $value, string $date = 'medium', string $time = 'none', ?string $locale = null): string
    {
        $locale ??= Locale::getDefault();

        $formatter = new IntlDateFormatter(
            $locale,
            $this->width($date),
            $this->width($time),
            $value->getTimezone(),
        );

        return (string) $formatter->format($value);
    }

    /**
     * Formats from a skeleton — which fields to show — and lets ICU order them:
     * 'dMMM' is "15 sept." in French and "Sep 15" in English. A literal pattern
     * would fix one order and get the other wrong.
     */
    public function skeleton(DateTimeInterface $value, string $skeleton, ?string $locale = null): string
    {
        $locale ??= Locale::getDefault();

        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $value->getTimezone(),
            null,
            new IntlDatePatternGenerator($locale)->getBestPattern($skeleton),
        );

        return (string) $formatter->format($value);
    }

    private function width(string $name): int
    {
        return array_key_exists($name, self::WIDTHS) ? self::WIDTHS[$name] : IntlDateFormatter::MEDIUM;
    }
}
