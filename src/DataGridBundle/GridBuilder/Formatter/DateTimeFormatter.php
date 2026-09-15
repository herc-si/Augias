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

namespace Augias\DataGridBundle\GridBuilder\Formatter;

use Augias\CoreBundle\Intl\LocalisedDate;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use DateTime;
use DateTimeInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @see \Augias\DataGridBundle\Tests\GridBuilder\Formatter\DateTimeFormatterTest
 */
class DateTimeFormatter implements FormatterInterface
{
    public function __construct(
        private readonly LocalisedDate $dates,
    ) {
    }

    public function format(Column $column, mixed $value): string | TranslatableMessage
    {
        if (null === $value) {
            return '';
        }

        assert($column instanceof DateTimeColumn);

        if (! $value instanceof DateTimeInterface) {
            $value = new DateTime($value);
        }

        return $this->dates->format($value, $column->getDateWidth(), $column->getTimeWidth());
    }
}
