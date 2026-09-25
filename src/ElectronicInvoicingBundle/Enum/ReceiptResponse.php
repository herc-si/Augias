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

    /**
     * fr:207 — the buyer disputes part of it, and says what. The invoice
     * stays open: once settled with the supplier, it is accepted or refused.
     */
    case Disputed = 'fr:207';

    /** fr:210 — the buyer refuses it, and says why. */
    case Refused = 'fr:210';

    public function translationKey(): string
    {
        return match ($this) {
            self::Accepted => 'einvoicing.response.accepted',
            self::Disputed => 'einvoicing.response.disputed',
            self::Refused => 'einvoicing.response.refused',
        };
    }

    public function needsReason(): bool
    {
        return self::Accepted !== $this;
    }

    /**
     * Whether nothing more can be answered after it. A dispute ends in an
     * acceptance or a refusal; those end it.
     */
    public function isFinal(): bool
    {
        return self::Disputed !== $this;
    }
}
