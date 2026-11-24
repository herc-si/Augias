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

/**
 * How long the assistant's journal is kept: a year, enough to answer for
 * what it did and to follow its use, as the processing register says.
 */
final class AssistantJournalRetention
{
    public const int DAYS = 365;
}
