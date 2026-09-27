<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Bank\Parser;

use Augias\AccountingBundle\Bank\UnreadableStatement;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;
use function mb_check_encoding;
use function mb_convert_encoding;
use function preg_replace;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function substr;
use function trim;

/**
 * What every format needs: text in UTF-8, French or English numbers, the date
 * shapes banks write.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\ParserTest
 */
final class StatementText
{
    private const array DATE_FORMATS = ['!d/m/Y', '!d/m/y', '!Y-m-d', '!d-m-Y', '!d.m.Y', '!Ymd', '!d-m-y'];

    /**
     * Banks still export in Windows-1252; the BOM some add is dropped.
     */
    public static function utf8(string $content): string
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
    }

    /**
     * "1 234,56", "-1,234.56", "1234.5", "12,50 €" — the decimal separator is
     * whichever of the two comes last.
     */
    public static function amount(string $value): BigDecimal
    {
        $clean = (string) preg_replace('/[^\d,.\-+]/u', '', $value);

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = strrpos($clean, ',') > strrpos($clean, '.')
                ? str_replace(['.', ','], ['', '.'], $clean)
                : str_replace(',', '', $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }

        try {
            return BigDecimal::of('' === trim($clean, '+-') ? 'x' : $clean);
        } catch (MathException) {
            throw new UnreadableStatement('invalid_amount', ['%value%' => $value]);
        }
    }

    public static function date(string $value): DateTimeImmutable
    {
        $value = trim($value);

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);

            if (false !== $date && $date->format(substr($format, 1)) === $value) {
                return $date;
            }
        }

        throw new UnreadableStatement('invalid_date', ['%value%' => $value]);
    }
}
