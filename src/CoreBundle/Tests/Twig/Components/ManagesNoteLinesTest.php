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

namespace Augias\CoreBundle\Tests\Twig\Components;

use Augias\CoreBundle\Twig\Components\ManagesNoteLines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManagesNoteLinesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function untouchedPriceProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'english' => ['0.00'];
        // What a French browser sends back for the line a document opens with:
        // it used to read as a typed price, so a catalogue entry went below it
        // and the blank line stayed.
        yield 'french' => ['0,00'];
    }

    #[DataProvider('untouchedPriceProvider')]
    public function testTheOpeningLineIsBlankWhateverTheLocale(string $price): void
    {
        self::assertSame(0, $this->lastBlankLine([0 => ['description' => '', 'price' => $price, 'qty' => '1']]));
    }

    public function testALineWithAPriceOrADescriptionIsNotBlank(): void
    {
        self::assertNull($this->lastBlankLine([0 => ['description' => '', 'price' => '0,50']]));
        self::assertNull($this->lastBlankLine([0 => ['description' => '', 'price' => '1 000,00']]));
        self::assertNull($this->lastBlankLine([0 => ['description' => 'Conseil', 'price' => '0,00']]));
        self::assertNull($this->lastBlankLine([0 => ['description' => '', 'price' => '0,00', 'note' => '1']]));
    }

    /**
     * @param array<array-key, mixed> $lines
     */
    private function lastBlankLine(array $lines): ?int
    {
        $editor = new class() {
            use ManagesNoteLines;

            /**
             * @var array<string, mixed>
             */
            public array $formValues = [];

            public function canAddLines(): bool
            {
                return true;
            }

            /**
             * @param array<array-key, mixed> $lines
             */
            public function blank(array $lines): ?int
            {
                return $this->lastBlankLine($lines);
            }
        };

        return $editor->blank($lines);
    }
}
