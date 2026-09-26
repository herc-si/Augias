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

namespace Augias\CoreBundle\Export\Attachment;

use Closure;

/**
 * One file in a company's export archive: where it goes, and how to get its
 * bytes — produced only when the archive is written, one file at a time.
 */
final readonly class ExportAttachment
{
    /**
     * @param Closure(): string $contents
     */
    public function __construct(
        public string $path,
        public Closure $contents,
    ) {
    }
}
