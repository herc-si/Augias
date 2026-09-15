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

namespace Augias\DataGridBundle\GridBuilder\Column;

use Override;

/**
 * RelativeDateColumn displays dates in relative format (e.g., "3 days ago").
 *
 * For dates older than the threshold, it falls back to absolute date format.
 * The absolute date is always shown as a tooltip on hover.
 *
 * Usage:
 *   RelativeDateColumn::new('created')
 *       ->threshold(7)  // Show relative for dates within 7 days
 *       ->absoluteWidth('long')  // Width of the absolute date in the tooltip
 * @see \Augias\DataGridBundle\Tests\GridBuilder\Column\RelativeDateColumnTest
 */
final class RelativeDateColumn extends DateTimeColumn
{
    #[Override]
    public static function new(string $field): static
    {
        return parent::new($field)
            ->cellClass('col-date');
    }

    /**
     * Number of days within which to show relative dates.
     * Dates older than this will show the absolute format.
     */
    private int $threshold = 7;

    /**
     * Width of the absolute date shown in the tooltip.
     */
    private string $absoluteWidth = 'medium';

    /**
     * Set the threshold in days for showing relative dates.
     * Dates older than this will display in absolute format.
     */
    public function threshold(int $days): self
    {
        $this->threshold = $days;
        return $this;
    }

    /**
     * Set the format for the absolute date shown in the tooltip.
     */
    public function absoluteWidth(string $width): self
    {
        $this->absoluteWidth = $width;

        return $this;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function getAbsoluteWidth(): string
    {
        return $this->absoluteWidth;
    }
}
