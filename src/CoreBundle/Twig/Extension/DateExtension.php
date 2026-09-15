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

namespace Augias\CoreBundle\Twig\Extension;

use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use Locale;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use function is_int;

/**
 * Formats a date from a *skeleton* — which fields to show — and lets ICU decide
 * the order and the separators for the reader's locale.
 *
 * {@see \Twig\Extra\Intl\IntlExtension} covers the named widths (medium, long),
 * and its `pattern` argument is a literal ICU pattern: 'd MMM' reads correctly
 * in French and backwards in English. A compact cell that wants a day and a
 * month and no year has no named width to ask for, which is what this is for:
 * the skeleton 'dMMM' becomes "15 sept." in French and "Sep 15" in English.
 *
 * @see \Augias\CoreBundle\Tests\Twig\Extension\DateExtensionTest
 */
final class DateExtension extends AbstractExtension
{
    /**
     * @return TwigFilter[]
     */
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_date_skeleton', $this->formatSkeleton(...)),
        ];
    }

    public function formatSkeleton(DateTimeInterface | string | int | null $date, string $skeleton, ?string $locale = null): string
    {
        if (null === $date) {
            return '';
        }

        if (! $date instanceof DateTimeInterface) {
            $date = new DateTimeImmutable(is_int($date) ? '@' . $date : $date);
        }

        $locale ??= Locale::getDefault();

        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            null,
            new IntlDatePatternGenerator($locale)->getBestPattern($skeleton),
        );

        return (string) $formatter->format($date);
    }
}
