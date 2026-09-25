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

namespace Augias\ElectronicInvoicingBundle\Enum;

/**
 * What a company answers about an invoice it received — the lifecycle status
 * the French e-invoicing framework carries back to the supplier.
 */
enum ReceiptResponse: string
{
    /** fr:205 — the buyer accepts the invoice as due. */
    case Accepted = 'fr:205';

    /** fr:210 — the buyer refuses it, and says why. */
    case Refused = 'fr:210';

    public function translationKey(): string
    {
        return match ($this) {
            self::Accepted => 'einvoicing.response.accepted',
            self::Refused => 'einvoicing.response.refused',
        };
    }

    public function needsReason(): bool
    {
        return $this === self::Refused;
    }
}
