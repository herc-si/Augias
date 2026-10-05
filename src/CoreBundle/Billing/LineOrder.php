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

namespace Augias\CoreBundle\Billing;

use function array_flip;
use function array_keys;
use function array_search;
use function count;
use function is_array;
use function is_numeric;
use function usort;

/**
 * The order of a document's lines, as the submitted form carries it: each
 * line has a position, and lines written before positions existed all sit at
 * 0, in the order they came.
 *
 * Works on the raw form values (lines keyed by their form index), so that the
 * editor and the form agree before any entity is touched. Moving a line swaps
 * positions, never contents: a line keeps its id, its taxes and the receipts
 * of a disbursement.
 */
final class LineOrder
{
    /**
     * The form keys of the lines, first to last.
     *
     * @param array<int|string, mixed> $lines
     *
     * @return list<int|string>
     */
    public static function keys(array $lines): array
    {
        $keys = array_keys($lines);
        $sequence = array_flip($keys);

        usort($keys, static function (int | string $a, int | string $b) use ($lines, $sequence): int {
            return [self::position($lines[$a]), $sequence[$a]] <=> [self::position($lines[$b]), $sequence[$b]];
        });

        return $keys;
    }

    /**
     * The lines with positions 0, 1, 2… in their order: duplicates (the old
     * lines, all at 0) and lines without one (just added) settled.
     *
     * @param array<int|string, mixed> $lines
     *
     * @return array<int|string, mixed>
     */
    public static function renumber(array $lines): array
    {
        foreach (self::keys($lines) as $rank => $key) {
            if (is_array($lines[$key])) {
                $lines[$key]['position'] = (string) $rank;
            }
        }

        return $lines;
    }

    /**
     * The lines with the given one moved one place up or down.
     *
     * @param array<int|string, mixed> $lines
     *
     * @return array<int|string, mixed>
     */
    public static function move(array $lines, int | string $key, bool $up): array
    {
        $lines = self::renumber($lines);
        $keys = self::keys($lines);
        $rank = array_search($key, $keys, false);

        if ($rank === false) {
            return $lines;
        }

        $other = $up ? $rank - 1 : $rank + 1;
        if (! isset($keys[$other])) {
            return $lines;
        }

        $lines[$key]['position'] = (string) $other;
        $lines[$keys[$other]]['position'] = (string) $rank;

        return $lines;
    }

    /**
     * The next free position, for a line added at the end.
     *
     * @param array<int|string, mixed> $lines
     */
    public static function next(array $lines): int
    {
        return count($lines);
    }

    private static function position(mixed $line): int
    {
        $position = is_array($line) ? ($line['position'] ?? null) : null;

        // A line just added has none yet: it goes last.
        return is_numeric($position) ? (int) $position : PHP_INT_MAX;
    }
}
