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

namespace Augias\CoreBundle\Tests\Enum;

use Augias\CoreBundle\Enum\QuantityUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuantityUnit::class)]
final class QuantityUnitTest extends TestCase
{
    /**
     * UN/ECE Recommendation 20, as EN 16931 expects in BT-130.
     */
    public function testEachUnitHasItsCodeForTheElectronicInvoice(): void
    {
        $codes = [];

        foreach (QuantityUnit::cases() as $unit) {
            $codes[$unit->value] = $unit->unCode();
        }

        self::assertSame([
            'unit' => 'C62',
            'hour' => 'HUR',
            'day' => 'DAY',
            'month' => 'MON',
            'kilogram' => 'KGM',
            'litre' => 'LTR',
            'metre' => 'MTR',
            'flat_rate' => 'LS',
        ], $codes);
    }

    public function testAPlainCountHasNoShortLabel(): void
    {
        self::assertNull(QuantityUnit::Unit->shortLabel());
        self::assertSame('catalog.unit_short.hour', QuantityUnit::Hour->shortLabel());
    }
}
