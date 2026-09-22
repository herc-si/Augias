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

namespace Augias\UserBundle;

/**
 * How long a sign-in stays in the journal.
 *
 * Named once, because three places have to agree: the command that deletes,
 * the two pages that tell the reader how far back they can look, and — off in
 * the documentation — the privacy policy that promises it.
 */
final class LoginRecordRetention
{
    public const int DAYS = 90;
}
