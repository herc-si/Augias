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

namespace Augias\ClientBundle\Import;

use RuntimeException;

/**
 * A file the client import cannot work with at all. `reason` is the last
 * segment of the `client.import.error.*` message shown to the user.
 */
final class UnreadableClientFile extends RuntimeException
{
    public const string EMPTY = 'empty';

    public const string NO_NAME_COLUMN = 'no_name_column';

    public const string TOO_MANY_ROWS = 'too_many_rows';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('Unreadable client file: ' . $reason);
    }
}
