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

namespace Augias\DataGridBundle\Filter;

use Augias\DataGridBundle\GridBuilder\Order\NumberedOrder;
use Augias\DataGridBundle\Source\ORMSource;
use Doctrine\ORM\QueryBuilder;
use function explode;
use function str_contains;

/**
 * @see \Augias\DataGridBundle\Tests\Filter\SortFilterTest
 */
final readonly class SortFilter implements FilterInterface
{
    public function __construct(
        private string $field,
        private string $direction = 'ASC',
        private bool $natural = false,
    ) {
    }

    public function filter(QueryBuilder $queryBuilder, mixed $value): void
    {
        if ($this->field === '' || $this->field === '0') {
            return;
        }

        if (str_contains($this->field, '.')) {
            $relation = explode('.', $this->field);

            $queryBuilder->join(ORMSource::ALIAS . '.' . $relation[0], $relation[0]);
            $field = $relation[0] . '.' . $relation[1];
        } else {
            $field = ORMSource::ALIAS . '.' . $this->field;
        }

        if (! $this->natural) {
            $queryBuilder->orderBy($field, $this->direction);

            return;
        }

        NumberedOrder::apply($queryBuilder, $field, $this->direction, replace: true);
    }
}
