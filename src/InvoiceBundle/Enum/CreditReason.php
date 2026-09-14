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
 * Why the client is owed money back. This is not bookkeeping detail: the reason
 * is printed on the document, and it is the first thing anyone auditing a
 * correction looks for.
 */
enum CreditReason: string
{
    /** The invoice should never have been raised, and is cancelled in full. */
    case Cancellation = 'cancellation';

    /** Goods came back, or work was not delivered. Usually a subset of the lines. */
    case Return = 'return';

    /** An agreed reduction on a price that was correct when invoiced. */
    case Rebate = 'rebate';

    /** A gesture that answers to no line on any invoice. */
    case CommercialGesture = 'commercial_gesture';

    /** The invoice was wrong — wrong rate, wrong quantity, wrong client. */
    case ErrorCorrection = 'error_correction';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cancellation => 'Cancellation',
            self::Return => 'Return',
            self::Rebate => 'Rebate',
            self::CommercialGesture => 'Commercial gesture',
            self::ErrorCorrection => 'Error correction',
        };
    }

    /**
     * The catalogue key for display. getLabel() stays untranslated for the
     * places that need a stable string — grids, filters, the API — the same
     * split the status enums use.
     */
    public function getTranslationKey(): string
    {
        return 'credit_note.reason.' . $this->value;
    }

    /**
     * Whether the reason describes a credit that stands on its own, with no
     * invoice behind it.
     */
    public function standsAlone(): bool
    {
        return self::CommercialGesture === $this;
    }
}
