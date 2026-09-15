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

namespace Augias\DataGridBundle\GridBuilder\Order;

use Doctrine\ORM\QueryBuilder;
use function sprintf;

/**
 * Orders a column of numbered values — invoice, quote and credit note numbers —
 * by the number inside them rather than character by character, which would put
 * "FACT-10" between "FACT-1" and "FACT-2".
 *
 * Length first groups the values by how many digits their number has; inside a
 * group the plain comparison is already the numeric one. LENGTH() is DQL's own
 * function, so this is the same SQL on every platform the project supports.
 *
 * The grouping follows the number only while the prefix and the suffix keep
 * their length — true within one numbering sequence, which is what a grid
 * column of these shows. Ordering across a change of prefix, or across a year
 * suffix rolling from a shorter to a longer number, needs the sequence stored
 * as a number of its own; this is the one place that would have to change.
 */
final readonly class NumberedOrder
{
    /**
     * @param bool $replace Whether to become the ordering (a column the user
     *                      sorted on) or to follow one already set (a
     *                      tie-breaker under a date).
     */
    public static function apply(QueryBuilder $queryBuilder, string $field, string $direction = 'ASC', bool $replace = false): void
    {
        $length = sprintf('LENGTH(%s)', $field);

        if ($replace) {
            $queryBuilder->orderBy($length, $direction);
        } else {
            $queryBuilder->addOrderBy($length, $direction);
        }

        $queryBuilder->addOrderBy($field, $direction);
    }
}
