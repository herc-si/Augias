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

use function count;

/**
 * What an import did, or would do: the same report serves the preview and the
 * import itself.
 */
final readonly class ClientImportReport
{
    /**
     * @param list<string>                                              $created        names of the clients created (or that would be)
     * @param list<array{line: int, name: string}>                      $duplicates     lines left out because a client of that name exists
     * @param list<array{line: int, name: string, errors: list<string>}> $rejected       lines that cannot become a client, and why
     * @param list<string>                                              $unknownColumns column titles nothing was read from
     */
    public function __construct(
        public array $created,
        public array $duplicates,
        public array $rejected,
        public array $unknownColumns,
    ) {
    }

    public function createdCount(): int
    {
        return count($this->created);
    }
}
