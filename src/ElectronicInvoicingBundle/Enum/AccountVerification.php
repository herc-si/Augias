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
 * Where the platform stands on the identity of the company behind the
 * credentials. Until it is verified, nothing can be sent or received.
 */
enum AccountVerification: string
{
    /** Linked to the company, with authority to act for it. */
    case Verified = 'verified';

    /** Something did not match — a name, a registration — and the platform's support is looking. */
    case NeedsReview = 'needs_review';

    /** The platform decided these credentials cannot act for the company. */
    case Failed = 'failed';

    /** The platform could not be asked: bad credentials, or unreachable. */
    case Unknown = 'unknown';

    public function translationKey(): string
    {
        return 'einvoicing.account.verification.' . $this->value;
    }

    public function isUsable(): bool
    {
        return $this === self::Verified;
    }
}
