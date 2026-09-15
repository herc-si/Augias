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

namespace Augias\DataGridBundle\Export;

use Augias\DataGridBundle\Filter\SearchFilter;
use Augias\DataGridBundle\Filter\SortFilter;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridInterface;
use Doctrine\ORM\QueryBuilder;
use function array_filter;
use function array_map;
use function array_pad;
use function explode;

/**
 * Applies a grid's sort, search, and per-column filter state to a QueryBuilder.
 *
 * Extracted from the DataGrid LiveComponent so the same filter pipeline can be
 * shared by the paginator path (for rendering a grid) and the export path.
 */
final class GridQueryService
{
    /**
     * @param array<string, mixed> $gridFilters
     */
    public function applyFilters(
        GridInterface $grid,
        QueryBuilder $builder,
        string $sort,
        string $search,
        array $gridFilters,
    ): void {
        // SortFilter's constructor expects (field, direction = ASC); explode on an
        // empty string yields a single empty element which SortFilter::filter()
        // already treats as a no-op. The early return keeps intent obvious and
        // avoids constructing a throw-away filter when sort is unset.
        if ($sort !== '') {
            [$sortField, $direction] = array_pad(explode(',', $sort, 2), 2, 'ASC');

            new SortFilter($sortField, $direction, $this->sortsNaturally($grid, $sortField))->filter($builder, null);
        }

        $searchFields = array_filter($grid->columns(), static fn (Column $column): bool => $column->isSearchable());
        $searchFields = array_map(static fn (Column $column): string => $column->getField(), $searchFields);
        new SearchFilter($searchFields)->filter($builder, $search);

        foreach ($grid->filters() as $column => $filter) {
            $filterValue = $gridFilters[$column] ?? '';

            if ($filterValue === '' || $filterValue === []) {
                continue;
            }

            $filter->filter($builder, $filterValue);
        }
    }

    /**
     * A column says whether its values are numbered; the sort state only
     * carries the field it was raised on.
     */
    private function sortsNaturally(GridInterface $grid, string $sortField): bool
    {
        foreach ($grid->columns() as $column) {
            if ($column->getSortableField() === $sortField) {
                return $column->hasNaturalSort();
            }
        }

        return false;
    }
}
