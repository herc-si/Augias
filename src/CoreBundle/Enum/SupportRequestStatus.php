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
 * Where a request for help stands.
 *
 * Expiry is not a status: a request runs out at the time the company chose,
 * whether or not anything wrote that down, so it is read off the clock rather
 * than off a column a scheduled task might not have reached yet.
 */
enum SupportRequestStatus: string
{
    /** Asked for; nobody has taken it yet. */
    case Pending = 'pending';

    /** Someone running the service has taken it and may open the company. */
    case Accepted = 'accepted';

    /** Closed by whoever took it, with an account of what was done. */
    case Resolved = 'resolved';

    /** Called off by the company. */
    case Revoked = 'revoked';

    public function isOpen(): bool
    {
        return self::Pending === $this || self::Accepted === $this;
    }

    public function labelKey(): string
    {
        return 'support.status.' . $this->value;
    }
}
