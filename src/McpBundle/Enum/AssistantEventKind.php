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

namespace Augias\McpBundle\Enum;

/**
 * What happened, in the assistant's journal.
 */
enum AssistantEventKind: string
{
    /** A question asked, typed or spoken: what the monthly quota counts. */
    case Question = 'question';

    /** A tool that reads, run as the model asked. */
    case Tool = 'tool';

    /** A change the model proposed, shown to the person. */
    case Proposed = 'proposed';

    /** A proposed change the person confirmed, and so made (or refused by the tool). */
    case Confirmed = 'confirmed';

    /** A proposed change the person cancelled. */
    case Cancelled = 'cancelled';

    /** The owner turned the assistant on or off. */
    case Activated = 'activated';

    case Deactivated = 'deactivated';
}
