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

namespace Augias\AccountingBundle\Bank;

/**
 * What an import did: lines added, lines already there.
 */
final readonly class ImportResult
{
    public function __construct(
        public string $format,
        public int $imported,
        public int $duplicates,
    ) {
    }
}
