<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Bank;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * One line as a statement file gives it: signed, in major units, before it
 * is tied to an account.
 */
final readonly class ParsedTransaction
{
    public function __construct(
        public DateTimeImmutable $date,
        /** Money in positive, money out negative, in major units (12.50). */
        public BigDecimal $amount,
        public string $label,
        public ?string $counterparty = null,
        /** The bank's own identifier of the line, when the format carries one. */
        public ?string $reference = null,
        public ?string $currency = null,
    ) {
    }
}
