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

namespace Augias\CoreBundle\Twig\Components;

use Augias\CoreBundle\Billing\LineOrder;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use function array_keys;
use function ctype_digit;
use function in_array;
use function is_array;
use function max;
use function trim;

/**
 * The note and ordering actions every document editor shares: a line of
 * text only, and moving a line up or down. Works on the form values, as
 * addFromCatalog() does: the form is rebuilt from them on every render.
 *
 * @property array<string, mixed> $formValues
 */
trait ManagesNoteLines
{
    /**
     * Whether lines may be added yet: not before the client is known, whose
     * currency and taxes the lines are priced in and the totals need.
     */
    abstract public function canAddLines(): bool;

    /**
     * A document opened without its client shows no line, only the empty list
     * that asks for the client: the first line opens once the client is known.
     * Runs before the form is submitted (priority 0), which rebuilds it from
     * the form values.
     */
    #[PreReRender(priority: 5)]
    public function openTheFirstLineOnceTheClientIsKnown(): void
    {
        $lines = $this->formValues['lines'] ?? [];

        if ($this->canAddLines() && (! is_array($lines) || $lines === [])) {
            $this->formValues['lines'] = [0 => []];
        }
    }

    /**
     * The last line, when nothing has been typed in it yet: the one a
     * document opens with, which a catalogue entry fills in.
     *
     * @param array<array-key, mixed> $lines
     */
    private function lastBlankLine(array $lines): ?int
    {
        if ($lines === []) {
            return null;
        }

        $index = max(array_keys($lines));
        $line = $lines[$index];

        if (! is_array($line) || ($line['note'] ?? false)) {
            return null;
        }

        $description = trim((string) ($line['description'] ?? ''));
        $price = trim((string) ($line['price'] ?? ''));

        return $description === '' && in_array($price, ['', '0', '0.00'], true) ? (int) $index : null;
    }

    #[LiveAction]
    public function addNote(): void
    {
        if (! $this->canAddLines()) {
            return;
        }

        $lines = is_array($this->formValues['lines'] ?? null) ? $this->formValues['lines'] : [];
        $lines = LineOrder::renumber($lines);

        $index = $lines === [] ? 0 : max(array_keys($lines)) + 1;
        $lines[$index] = [
            'description' => '',
            'note' => '1',
            'position' => (string) LineOrder::next($lines),
            'price' => '0',
            'qty' => '0',
            'unit' => 'unit',
        ];

        $this->formValues['lines'] = $lines;
    }

    #[LiveAction]
    public function moveLine(#[LiveArg] string $line, #[LiveArg] string $direction): void
    {
        $lines = is_array($this->formValues['lines'] ?? null) ? $this->formValues['lines'] : [];
        $key = ctype_digit($line) ? (int) $line : $line;

        if (! isset($lines[$key])) {
            return;
        }

        $this->formValues['lines'] = LineOrder::move($lines, $key, 'up' === $direction);
    }
}
