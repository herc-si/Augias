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

namespace Augias\AccountingBundle\Export;

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\CoreBundle\Export\Attachment\ExportAttachment;
use Augias\CoreBundle\Export\Attachment\ExportAttachmentProvider;
use Augias\CoreBundle\Storage\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use RuntimeException;
use function file_get_contents;
use function preg_replace;

/**
 * The receipts attached to the books — a supplier's invoice, a bank slip —
 * for the company's export: the vouchers behind its entries, which it has to
 * keep as long as the entries themselves.
 */
final readonly class EntryAttachmentsProvider implements ExportAttachmentProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentStorage $storage,
    ) {
    }

    public function attachments(): Generator
    {
        foreach ($this->entityManager->getRepository(EntryAttachment::class)->findAll() as $attachment) {
            try {
                $path = $this->storage->path($attachment->getStoragePath());
            } catch (RuntimeException) {
                continue;
            }

            yield new ExportAttachment(
                'accounting-attachments/' . $attachment->getId() . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment->getFilename()),
                static fn (): string => (string) file_get_contents($path),
            );
        }
    }
}
