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

use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateTimeColumn::class)]
final class DateTimeColumnTest extends TestCase
{
    public function testFormat(): void
    {
        $column = DateTimeColumn::new('date');

        self::assertSame('medium', $column->getDateWidth());
        self::assertSame('short', $column->getTimeWidth());

        $column->width('long');

        self::assertSame('long', $column->getDateWidth());
        // A width given on its own drops the time: a column that wants one says so.
        self::assertSame('none', $column->getTimeWidth());

        $column->width('short', 'medium');

        self::assertSame('short', $column->getDateWidth());
        self::assertSame('medium', $column->getTimeWidth());
    }
}
