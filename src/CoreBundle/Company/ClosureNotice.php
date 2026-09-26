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
 * The three moments the people running a company are written to about its
 * closure.
 */
enum ClosureNotice: string
{
    /** Just asked for: when it goes, that it is read-only, what to do. */
    case Scheduled = 'scheduled';

    /** A week before: the last reminder to take the data out. */
    case Reminder = 'reminder';

    /** Done: the company and its data are gone. */
    case Deleted = 'deleted';
}
