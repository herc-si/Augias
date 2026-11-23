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

namespace Augias\UserBundle\Enum;

/**
 * How a sign-in got past the second factor, recorded with it in the journal
 * (Rémi, 23/11/2026): a code typed, a trusted device that skipped it, the
 * "remember me" cookie, or no second factor at all.
 */
enum SecondFactor: string
{
    /** A code from the app or the email, or a backup code. */
    case Code = 'code';

    /** A device the holder trusted for 30 days; see the trusted-device list. */
    case TrustedDevice = 'trusted_device';

    /** The "remember me" cookie: no password typed, so no code either. */
    case RememberMe = 'remember_me';

    /** The account has no second factor. */
    case None = 'none';

    public function translationKey(): string
    {
        return 'login_history.second_factor.' . $this->value;
    }
}
