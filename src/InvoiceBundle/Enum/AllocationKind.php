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

namespace Augias\InvoiceBundle\Enum;

/**
 * How a credit note was used up.
 *
 * The distinction is not presentational: under cash-basis books an offset
 * corrects itself — the client simply pays less, and the smaller receipt is
 * already the whole truth — while a refund is money leaving, and that has to be
 * written down.
 */
enum AllocationKind: string
{
    /** Set against what the client owes on another invoice. */
    case Offset = 'offset';

    /** Paid back. Money left the account. */
    case Refund = 'refund';

    public function getLabel(): string
    {
        return match ($this) {
            self::Offset => 'Set against an invoice',
            self::Refund => 'Refunded',
        };
    }

    public function getTranslationKey(): string
    {
        return 'credit_note.allocation.kind_label.' . $this->value;
    }

    /**
     * Whether this movement is one the books have to record on its own. An
     * offset needs no entry: the payment that follows is already smaller.
     */
    public function movesMoney(): bool
    {
        return self::Refund === $this;
    }
}
