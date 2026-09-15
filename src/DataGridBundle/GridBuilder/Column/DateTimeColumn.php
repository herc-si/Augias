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
 * @see \Augias\DataGridBundle\Tests\GridBuilder\Column\DateTimeColumnTest
 */
class DateTimeColumn extends Column
{
    #[Override]
    public static function new(string $field): static
    {
        return parent::new($field)
            ->cellClass('col-date');
    }

    /**
     * ICU widths rather than a PHP format string: a format string spells the
     * month out in English whatever language the page is in, which is what this
     * replaced. One of none, short, medium, long, full.
     */
    private string $dateWidth = 'medium';

    private string $timeWidth = 'short';

    public function width(string $date, string $time = 'none'): self
    {
        $this->dateWidth = $date;
        $this->timeWidth = $time;

        return $this;
    }

    public function getDateWidth(): string
    {
        return $this->dateWidth;
    }

    public function getTimeWidth(): string
    {
        return $this->timeWidth;
    }
}
