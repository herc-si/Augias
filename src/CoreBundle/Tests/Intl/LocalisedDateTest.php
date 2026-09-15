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

namespace Augias\CoreBundle\Tests\Intl;

use Augias\CoreBundle\Intl\LocalisedDate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalisedDate::class)]
final class LocalisedDateTest extends TestCase
{
    private LocalisedDate $dates;

    private DateTimeImmutable $date;

    protected function setUp(): void
    {
        $this->dates = new LocalisedDate();
        $this->date = new DateTimeImmutable('2026-09-15 14:30:00');
    }

    #[DataProvider('widths')]
    public function testAWidthIsWrittenTheWayTheLocaleWritesIt(string $locale, string $width, string $expected): void
    {
        self::assertSame($expected, $this->dates->format($this->date, $width, 'none', $locale));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function widths(): iterable
    {
        yield 'French long' => ['fr_FR', 'long', '15 septembre 2026'];
        yield 'English long' => ['en_US', 'long', 'September 15, 2026'];
        yield 'French medium' => ['fr_FR', 'medium', '15 sept. 2026'];
        yield 'English medium' => ['en_US', 'medium', 'Sep 15, 2026'];
    }

    /**
     * A skeleton says which fields to show and leaves the order to ICU, which a
     * literal pattern cannot do: 'd MMM' is right in French and backwards in
     * English.
     */
    public function testASkeletonIsOrderedByTheLocale(): void
    {
        self::assertSame('15 sept.', $this->dates->skeleton($this->date, 'dMMM', 'fr_FR'));
        self::assertSame('Sep 15', $this->dates->skeleton($this->date, 'dMMM', 'en_US'));
    }

    public function testATimeCanBeAskedForAlongsideTheDate(): void
    {
        $withTime = $this->dates->format($this->date, 'short', 'short', 'fr_FR');

        self::assertStringContainsString('15/09/2026', $withTime);
        self::assertStringContainsString('14:30', $withTime);
    }

    /**
     * A width nobody defined falls back rather than throwing: a grid column is
     * configuration, and a typo in it should not take the page down.
     */
    public function testAnUnknownWidthFallsBackToMedium(): void
    {
        self::assertSame(
            $this->dates->format($this->date, 'medium', 'none', 'fr_FR'),
            $this->dates->format($this->date, 'enormous', 'none', 'fr_FR'),
        );
    }
}
