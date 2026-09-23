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

namespace Augias\CoreBundle\Storage;

/**
 * What {@see DocumentStorage} wrote, as the row that points at it records it.
 *
 * Whoever keeps the file — a book entry, an invoice line — copies these onto
 * its own row. The storage knows nothing about who that is.
 */
final readonly class StoredDocument
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $size,
        public string $checksum,
        public string $storagePath,
    ) {
    }
}
