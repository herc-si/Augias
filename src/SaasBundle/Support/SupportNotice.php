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

namespace Augias\SaasBundle\Support;

enum SupportNotice: string
{
    /** To whoever answers: a company has asked. */
    case Requested = 'requested';

    /** To the company: someone has taken it and may now come in. */
    case Accepted = 'accepted';

    /** To the company: closed, and what was done. */
    case Resolved = 'resolved';
}
