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

namespace Augias\CoreBundle\Company;

/**
 * Why a company is closing, which decides what its people are told and how
 * the closure is called off.
 */
enum ClosureReason: string
{
    /** The owner asked for it, and may cancel it until the date. */
    case Requested = 'requested';

    /**
     * The hosted service's subscription ended: the data is kept ninety days
     * so it can be exported, then deleted. Renewing is what calls it off.
     */
    case SubscriptionEnded = 'subscription_ended';

    /** Where the emails' wording is found. */
    public function emailKeys(): string
    {
        return match ($this) {
            self::Requested => 'company.closing.email',
            self::SubscriptionEnded => 'company.subscription_ended.email',
        };
    }
}
