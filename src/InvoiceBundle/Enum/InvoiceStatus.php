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

use Augias\CoreBundle\Enum\HasStatusLabel;

enum InvoiceStatus: string implements HasStatusLabel
{
    case New = 'new';
    case Draft = 'draft';
    case Pending = 'pending';
    case Paid = 'paid';
    /** Settled by a credit note set against it rather than by a payment. */
    case Credited = 'credited';
    case Active = 'active';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Credited => 'Credited',
            self::Active => 'Active',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
            self::Archived => 'Archived',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Draft => 'gray',
            self::Pending => 'yellow',
            self::Paid => 'green',
            self::Credited => 'cyan',
            self::Active => 'green',
            self::Overdue => 'red',
            self::Cancelled => 'dark',
            self::Archived => 'purple',
        };
    }
}
