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

use DateTimeImmutable;

/**
 * Something in the application a bank line could be the trace of.
 */
final readonly class Suggestion
{
    /** A payment already recorded: the line only confirms it. */
    public const string PAYMENT = 'payment';

    /** An invoice still owed: matching records its payment. */
    public const string INVOICE = 'invoice';

    public const string BILL_PAYMENT = 'bill_payment';

    public const string BILL = 'bill';

    public function __construct(
        public string $kind,
        public string $id,
        public string $label,
        public string $counterparty,
        public ?DateTimeImmutable $date,
        public int $score,
    ) {
    }

    /** Whether matching writes something new, rather than tying to what exists. */
    public function records(): bool
    {
        return self::INVOICE === $this->kind || self::BILL === $this->kind;
    }
}
