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

use Augias\CoreBundle\Enum\HasStatusLabel;

/**
 * Augias's own normalization of a provider's raw, provider-specific
 * submission/status-code vocabulary (e.g. SUPER PDP's `fr:205`/`api:rejected`
 * codes) into the three outcomes that matter for display and notifications.
 *
 * This is deliberately separate from {@see \Augias\InvoiceBundle\Enum\InvoiceStatus}:
 * the invoice's own workflow tracks the business document lifecycle (draft,
 * sent, paid, ...), while this tracks the transmission's own lifecycle on the
 * electronic-invoicing platform. Neither drives the other automatically.
 */
enum ElectronicInvoiceProcessingStatus: string implements HasStatusLabel
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /** The client disputes part of it (fr:207): up to the company to settle. */
    case Disputed = 'disputed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Disputed => 'Disputed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Accepted => 'green',
            self::Rejected => 'red',
            self::Disputed => 'orange',
        };
    }
}
