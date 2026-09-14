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

/**
 * A credit note has a much shorter life than an invoice: it is written, it is
 * handed to the client, and it is used up. Nothing cancels it — a credit note
 * issued in error is itself corrected by an invoice.
 */
enum CreditNoteStatus: string implements HasStatusLabel
{
    /** Still being written. The only state in which it can be changed or deleted. */
    case Draft = 'draft';

    /** Numbered and handed over. Immutable from here on, and owed to the client. */
    case Issued = 'issued';

    /** Used up, whether refunded or set against later invoices. */
    case Settled = 'settled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Settled => 'Settled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'yellow',
            self::Settled => 'green',
        };
    }
}
