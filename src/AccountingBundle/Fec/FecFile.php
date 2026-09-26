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

namespace Augias\AccountingBundle\Fec;

use DateTimeImmutable;

/**
 * A FEC for one financial year, ready to hand over, with its notice.
 */
final readonly class FecFile
{
    public function __construct(
        public string $filename,
        public string $content,
        public string $notice,
        public FecVariant $variant,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $entries,
    ) {
    }
}
