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

namespace Augias\ClientBundle\Tests\Registry;

use Augias\ClientBundle\Registry\Towns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Towns::class)]
final class TownsTest extends TestCase
{
    public function testATownIsFoundHoweverItIsWritten(): void
    {
        $towns = new Towns(__DIR__ . '/towns.json');

        self::assertSame('42000,42100', $towns->postcodes('Saint-Étienne'));
        self::assertSame('42000,42100', $towns->postcodes('st etienne'));
        self::assertSame('38200', $towns->postcodes('VIENNE'));
        self::assertNull($towns->postcodes('lyonn'));
    }

    /**
     * The list kept with the application, as augias:registry:update-towns
     * writes it.
     */
    public function testTheListKeptHasTheTownsOfFrance(): void
    {
        $towns = new Towns();

        self::assertSame('38270', $towns->postcodes('Bellegarde-Poussieu'));
        self::assertSame('38200', $towns->postcodes('Vienne'));
        self::assertStringContainsString('75116', (string) $towns->postcodes('Paris'));
        self::assertStringContainsString('69009', (string) $towns->postcodes('Lyon'));
    }
}
