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

namespace Augias\CoreBundle;

/**
 * How long the journal remembers what was opened.
 *
 * Three places have to agree: the command that deletes, the page that tells
 * the reader how far back it goes, and the privacy policy that promises it.
 * The same ninety days as the sign-in journal, for the same reason — long
 * enough to answer a question about last quarter, short enough not to become
 * a permanent record of somebody's working habits.
 */
final class AccessJournalRetention
{
    public const int DAYS = 90;
}
