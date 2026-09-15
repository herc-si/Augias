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

namespace Augias\DataGridBundle\Tests\GridBuilder\Column;

use Augias\DataGridBundle\GridBuilder\Column\RelativeDateColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RelativeDateColumn::class)]
final class RelativeDateColumnTest extends TestCase
{
    private RelativeDateColumn $column;

    protected function setUp(): void
    {
        $this->column = RelativeDateColumn::new('created');
    }

    public function testNewSetsCellClassToColDate(): void
    {
        self::assertSame('col-date', $this->column->getCellClass());
    }

    public function testGetThresholdReturnsSevenByDefault(): void
    {
        self::assertSame(7, $this->column->getThreshold());
    }

    public function testThresholdSetsAndGetsCorrectly(): void
    {
        $result = $this->column->threshold(30);

        self::assertSame($this->column, $result);
        self::assertSame(30, $this->column->getThreshold());
    }

    public function testGetAbsoluteWidthReturnsDefaultWidth(): void
    {
        self::assertSame('medium', $this->column->getAbsoluteWidth());
    }

    public function testAbsoluteWidthSetsAndGetsCorrectly(): void
    {
        $result = $this->column->absoluteWidth('long');

        self::assertSame($this->column, $result);
        self::assertSame('long', $this->column->getAbsoluteWidth());
    }

    public function testFluentInterface(): void
    {
        $result = $this->column
            ->threshold(14)
            ->absoluteWidth('full')
            ->label('Created At')
            ->sortable(true);

        self::assertSame($this->column, $result);
        self::assertSame(14, $this->column->getThreshold());
        self::assertSame('full', $this->column->getAbsoluteWidth());
    }

    public function testInheritsFromDateTimeColumn(): void
    {
        // Test that it has inherited properties from DateTimeColumn
        self::assertSame('created', $this->column->getField());
        self::assertTrue($this->column->isSortable());
    }
}
