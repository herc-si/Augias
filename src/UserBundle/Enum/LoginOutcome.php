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
 * What happened when someone tried to sign in.
 *
 * Failures are kept, not only successes: an account journal that shows nothing
 * until an attempt worked cannot answer the question it exists for, which is
 * "has anyone been trying to get into my account".
 */
enum LoginOutcome: string
{
    /** Signed in, second factor included where one was required. */
    case Success = 'success';

    /** The password was wrong, or the account does not exist. */
    case Failure = 'failure';

    /** The password was right and the second factor was not. */
    case TwoFactorFailure = 'two_factor_failure';

    case SignedOut = 'signed_out';

    public function translationKey(): string
    {
        return 'login_history.outcome.' . $this->value;
    }

    /**
     * Tabler's contextual colour for this outcome, for the badge on the page.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Success => 'bg-success-lt',
            self::Failure, self::TwoFactorFailure => 'bg-danger-lt',
            self::SignedOut => 'bg-secondary-lt',
        };
    }
}
