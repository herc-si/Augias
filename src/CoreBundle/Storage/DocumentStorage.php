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

use Augias\CoreBundle\Entity\Company;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Ulid;
use function basename;
use function dirname;
use function hash_file;
use function is_file;
use function sprintf;
use function str_contains;

/**
 * Where a supporting document is written, and how it is found again — the
 * receipt behind a book entry, the supplier's invoice behind a disbursement.
 *
 * On disk rather than in the database, because these are whole PDFs and
 * photographs: a dump that carries six years of them is a dump nobody restores.
 * The root is `AUGIAS_ATTACHMENTS_DIR`, which an install can point at its own
 * volume — these records outlive the container they were uploaded from.
 *
 * The layout is `<company>/<year>/<ulid>.<ext>`, and every part of it is chosen
 * here rather than taken from the upload:
 *
 * - by company first, so one tenant's documents can be handed over, archived or
 *   destroyed without touching another's;
 * - by year, because that is how the retention obligation is counted, and a
 *   directory with six years of every document in it is a directory nobody can
 *   work with;
 * - a fresh ULID for the name, never the uploaded one. A filename is chosen by
 *   whoever produced the document, and `../` is a filename too. The original is
 *   kept in the row and given back on download, where it is a label rather than
 *   a path.
 *
 * The extension follows the type that was accepted, not the name that was sent,
 * for the same reason.
 *
 * @see \Augias\AccountingBundle\Tests\Service\AttachmentStorageTest
 */
final readonly class DocumentStorage
{
    /**
     * The types a supporting document may have, and the extension each is
     * written with.
     *
     * A short list on purpose: a supporting document is a document. SVG is
     * absent for the reason {@see \Augias\CoreBundle\Form\Type\ImageUploadType}
     * gives — it can carry script — and so is anything executable.
     */
    public const array ALLOWED_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/tiff' => 'tiff',
    ];

    /** 10 MB — a scanned invoice, not a film. */
    public const int MAX_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private string $root,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Writes the upload and describes what was written.
     *
     * @throws RuntimeException when the type is not one a supporting document may have
     */
    public function store(UploadedFile $file, Company $company): StoredDocument
    {
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $extension = self::ALLOWED_TYPES[$mimeType] ?? null;

        if (null === $extension) {
            throw new RuntimeException(sprintf('Refusing to store a file of type "%s".', $mimeType));
        }

        $size = $file->getSize();
        $checksum = hash_file('sha256', $file->getPathname());

        if (false === $size || false === $checksum) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $path = sprintf(
            '%s/%s/%s.%s',
            (string) $company->getId(),
            new DateTimeImmutable('today')->format('Y'),
            new Ulid(),
            $extension,
        );

        $file->move($this->root . '/' . dirname($path), basename($path));

        return new StoredDocument($file->getClientOriginalName(), $mimeType, $size, $checksum, $path);
    }

    /**
     * The absolute path of a stored document.
     *
     * @throws RuntimeException when the row points at a file that is not there —
     *                          a deleted volume, a restore that left the
     *                          database ahead of the disk. Saying so beats
     *                          serving an empty response as if nothing were
     *                          wrong.
     */
    public function path(string $storagePath): string
    {
        // Nothing in the application writes a traversing path, but this is the
        // one place a stored string becomes a filesystem read, so it is the
        // place to refuse one.
        if (str_contains($storagePath, '..')) {
            throw new RuntimeException('Refusing to read outside the attachment root.');
        }

        $absolute = $this->root . '/' . $storagePath;

        if (! is_file($absolute)) {
            throw new RuntimeException(sprintf('The stored document "%s" is missing from disk.', $storagePath));
        }

        return $absolute;
    }

    /**
     * Removes a stored document. Missing is not an error: the row is going
     * away, and the point is that the file is gone with it.
     */
    public function remove(string $storagePath): void
    {
        if (str_contains($storagePath, '..')) {
            return;
        }

        $this->filesystem->remove($this->root . '/' . $storagePath);
    }
}
