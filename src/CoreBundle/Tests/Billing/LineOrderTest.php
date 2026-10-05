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

namespace Augias\CoreBundle\Tests\Billing;

use Augias\CoreBundle\Billing\LineOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineOrder::class)]
final class LineOrderTest extends TestCase
{
    /**
     * Lines written before positions existed are all at 0: they keep the
     * order they came in, and a line just added (no position yet) goes last.
     */
    public function testOldLinesKeepTheirOrderAndNewOnesGoLast(): void
    {
        $lines = LineOrder::renumber([
            0 => ['description' => 'a', 'position' => '0'],
            1 => ['description' => 'b', 'position' => '0'],
            2 => ['description' => 'new'],
        ]);

        self::assertSame(['0', '1', '2'], [$lines[0]['position'], $lines[1]['position'], $lines[2]['position']]);
    }

    public function testAMoveSwapsPositionsNotContents(): void
    {
        $lines = [
            0 => ['description' => 'a', 'position' => '0'],
            1 => ['description' => 'b', 'position' => '1'],
            2 => ['description' => 'c', 'position' => '2'],
        ];

        $moved = LineOrder::move($lines, 2, true);

        self::assertSame('c', $moved[2]['description']);
        self::assertSame([0, 2, 1], LineOrder::keys($moved));
    }

    public function testTheEndsStayPut(): void
    {
        $lines = [
            0 => ['description' => 'a', 'position' => '0'],
            1 => ['description' => 'b', 'position' => '1'],
        ];

        self::assertSame([0, 1], LineOrder::keys(LineOrder::move($lines, 0, true)));
        self::assertSame([0, 1], LineOrder::keys(LineOrder::move($lines, 1, false)));
        self::assertSame([0, 1], LineOrder::keys(LineOrder::move($lines, 7, true)), 'An unknown line moves nothing.');
    }
}
