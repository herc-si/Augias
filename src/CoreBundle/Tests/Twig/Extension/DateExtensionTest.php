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

namespace Augias\CoreBundle\Tests\Twig\Extension;

use Augias\CoreBundle\Intl\LocalisedDate;
use Augias\CoreBundle\Twig\Extension\DateExtension;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateExtension::class)]
final class DateExtensionTest extends TestCase
{
    private DateExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new DateExtension(new LocalisedDate());
    }

    /**
     * The point of a skeleton: the same request for "a day and a month" comes
     * out in the order each language writes it.
     */
    #[DataProvider('compactDates')]
    public function testASkeletonIsOrderedByTheLocale(string $locale, string $expected): void
    {
        self::assertSame($expected, $this->extension->formatSkeleton(new DateTimeImmutable('2026-09-15'), 'dMMM', $locale));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compactDates(): iterable
    {
        yield 'French puts the day first' => ['fr_FR', '15 sept.'];
        yield 'English puts the month first' => ['en_US', 'Sep 15'];
        yield 'Japanese writes neither' => ['ja_JP', '9月15日'];
    }

    public function testAMonthOnItsOwnIsNamedInTheLocale(): void
    {
        $date = new DateTimeImmutable('2026-09-15');

        self::assertSame('septembre', $this->extension->formatSkeleton($date, 'MMMM', 'fr_FR'));
        self::assertSame('September', $this->extension->formatSkeleton($date, 'MMMM', 'en_US'));
    }

    public function testAStringIsAcceptedTheWayTwigsOwnDateFilterAcceptsOne(): void
    {
        self::assertSame('15 sept.', $this->extension->formatSkeleton('2026-09-15', 'dMMM', 'fr_FR'));
    }

    /**
     * A nullable column renders as nothing rather than as today.
     */
    public function testNothingIsFormattedAsNothing(): void
    {
        self::assertSame('', $this->extension->formatSkeleton(null, 'dMMM', 'fr_FR'));
    }
}
