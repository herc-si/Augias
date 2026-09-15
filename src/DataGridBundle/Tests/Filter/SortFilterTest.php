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

namespace Augias\DataGridBundle\Tests\Filter;

use Augias\DataGridBundle\Filter\SortFilter;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(SortFilter::class)]
final class SortFilterTest extends TestCase
{
    private SortFilter $filter;

    private QueryBuilder & MockObject $queryBuilder;

    protected function setUp(): void
    {
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->filter = new SortFilter('field');
    }

    public function testFilterAppliesCorrectOrderingWhenFieldIsSet(): void
    {
        $this->queryBuilder
            ->expects($this->once())
            ->method('orderBy')
            ->with('d.field', 'ASC');

        $this->filter->filter($this->queryBuilder, null);
    }

    public function testFilterDoesNotApplyOrderingWhenFieldIsNotSet(): void
    {
        $sortFilter = new SortFilter('');

        $this->queryBuilder
            ->expects($this->never())
            ->method('orderBy');

        $sortFilter->filter($this->queryBuilder, null);
    }

    public function testFilterAppliesCorrectOrderingWhenDirectionIsDesc(): void
    {
        $sortFilter = new SortFilter('field', 'DESC');

        $this->queryBuilder
            ->expects($this->once())
            ->method('orderBy')
            ->with('d.field', 'DESC');

        $sortFilter->filter($this->queryBuilder, null);
    }

    public function testANaturalSortOrdersByLengthBeforeValue(): void
    {
        $sortFilter = new SortFilter('invoiceId', 'ASC', true);

        $this->queryBuilder
            ->expects($this->once())
            ->method('orderBy')
            ->with('LENGTH(d.invoiceId)', 'ASC')
            ->willReturnSelf();

        $this->queryBuilder
            ->expects($this->once())
            ->method('addOrderBy')
            ->with('d.invoiceId', 'ASC')
            ->willReturnSelf();

        $sortFilter->filter($this->queryBuilder, null);
    }

    public function testAPlainSortDoesNotAddASecondOrdering(): void
    {
        $this->queryBuilder
            ->expects($this->never())
            ->method('addOrderBy');

        $this->filter->filter($this->queryBuilder, null);
    }
}
