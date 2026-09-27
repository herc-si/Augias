<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\BillBundle\Import;

use RuntimeException;

/**
 * A file that carries no invoice Augias can read: `reason` says why, for the
 * message the user reads.
 */
final class UnreadableInvoice extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
