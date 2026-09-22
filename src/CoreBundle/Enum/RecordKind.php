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

namespace Augias\CoreBundle\Enum;

/**
 * The kinds of record whose opening is worth remembering.
 *
 * Deliberately short. The journal answers "what did I look at", and a list
 * that also held every dashboard and every settings screen would answer it
 * worse: the line that matters would be buried under the ones nobody asks
 * about. What is here is what a person opens on purpose, one at a time.
 */
enum RecordKind: string
{
    case Invoice = 'invoice';

    case CreditNote = 'credit_note';

    case Quote = 'quote';

    case Client = 'client';

    case Bill = 'bill';

    public function translationKey(): string
    {
        return 'access_log.kind.' . $this->value;
    }

    /**
     * The route that opens this kind again, so a line in the journal is a way
     * back to what it names.
     */
    public function viewRoute(): string
    {
        return match ($this) {
            self::Invoice => '_invoices_view',
            self::CreditNote => '_credit_notes_view',
            self::Quote => '_quotes_view',
            self::Client => '_clients_view',
            self::Bill => '_bills_view',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Invoice, self::CreditNote, self::Bill => 'file-invoice',
            self::Quote => 'file-text',
            self::Client => 'users',
        };
    }
}
