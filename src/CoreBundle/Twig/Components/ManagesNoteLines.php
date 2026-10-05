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
use function array_keys;
use function ctype_digit;
use function is_array;
use function max;

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
