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

namespace Augias\AccountingBundle\Service;

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Storage\DocumentStorage;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The supporting documents behind book entries.
 *
 * Where and how they are written is {@see DocumentStorage}'s business, shared
 * with the receipts behind disbursements; this only turns what it wrote into
 * the row an entry keeps.
 *
 * @see \Augias\AccountingBundle\Tests\Service\AttachmentStorageTest
 */
final readonly class AttachmentStorage
{
    /**
     * The types a book entry may carry — those of any supporting document.
     */
    public const array ALLOWED_TYPES = DocumentStorage::ALLOWED_TYPES;

    public const int MAX_SIZE = DocumentStorage::MAX_SIZE;

    public function __construct(
        private DocumentStorage $documents,
    ) {
    }

    /**
     * Writes the upload and returns the row that describes it, ready to be tied
     * to an entry and persisted.
     *
     * @throws RuntimeException when the type is not one a book entry may carry
     */
    public function store(UploadedFile $file, Company $company): EntryAttachment
    {
        $stored = $this->documents->store($file, $company);

        return new EntryAttachment()
            ->setFilename($stored->filename)
            ->setMimeType($stored->mimeType)
            ->setSize($stored->size)
            ->setChecksum($stored->checksum)
            ->setStoragePath($stored->storagePath);
    }

    /**
     * @throws RuntimeException when the file is not on disk
     */
    public function path(EntryAttachment $attachment): string
    {
        return $this->documents->path($attachment->getStoragePath());
    }

    public function remove(EntryAttachment $attachment): void
    {
        $this->documents->remove($attachment->getStoragePath());
    }
}
