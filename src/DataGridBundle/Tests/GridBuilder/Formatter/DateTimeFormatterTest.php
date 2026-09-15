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

namespace Augias\DataGridBundle\Tests\GridBuilder\Formatter;

use Augias\CoreBundle\Intl\LocalisedDate;
use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use Augias\DataGridBundle\GridBuilder\Formatter\DateTimeFormatter;
use Carbon\Carbon;
use Locale;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateTimeFormatter::class)]
final class DateTimeFormatterTest extends TestCase
{
    public function testFormat(): void
    {
        $formatter = new DateTimeFormatter(new LocalisedDate());

        Locale::setDefault('en_US');

        // The default carries a time, whose separator before AM/PM is a narrow
        // no-break space that varies with the ICU version — so the date is what
        // is asserted, and that a time followed it at all.
        $default = $formatter->format(DateTimeColumn::new('date'), Carbon::parse('2021-01-12 12:13:14'));

        self::assertStringStartsWith('Jan 12, 2021', $default);
        self::assertStringContainsString('12:13', $default);
        self::assertSame('January 12, 2021', $formatter->format(DateTimeColumn::new('date')->width('long'), Carbon::parse('2021-01-12 12:13:14')));
        self::assertSame('January 1, 2021', $formatter->format(DateTimeColumn::new('date')->width('long'), '2021-01-01 00:00:00'));
    }

    /**
     * The whole point: the same column, read by someone else.
     */
    public function testTheSameColumnInAnotherLanguage(): void
    {
        $formatter = new DateTimeFormatter(new LocalisedDate());

        Locale::setDefault('fr_FR');

        self::assertSame('12 janvier 2021', $formatter->format(DateTimeColumn::new('date')->width('long'), Carbon::parse('2021-01-12 12:13:14')));
    }

    protected function tearDown(): void
    {
        Locale::setDefault('en_US');

        parent::tearDown();
    }
}
