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

namespace Augias\McpBundle\Assistant;

enum AssistantAccessStatus: string
{
    case Ready = 'ready';

    /** The plan includes no question. */
    case NotInPlan = 'not_in_plan';

    /** The owner has not turned it on. */
    case Off = 'off';

    /** This month's questions are used up. */
    case QuotaReached = 'quota_reached';
}
