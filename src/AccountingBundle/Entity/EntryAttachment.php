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

namespace Augias\AccountingBundle\Entity;

use Augias\AccountingBundle\Repository\EntryAttachmentRepository;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The document behind a book entry — the invoice, the receipt, the bank slip.
 *
 * The books have always carried a {@see LedgerEntry::$documentReference}, which
 * names the piece of paper. This holds the paper itself, because a reference to
 * a document nobody can produce is worth very little in a control: the accounts
 * have to justify the operations they record, and deducting VAT supposes
 * holding the invoice that carries it (CGI, art. 271-II).
 *
 * The file lives on disk, not in this row — see
 * {@see \Augias\AccountingBundle\Service\AttachmentStorage}, which owns the
 * layout. What is stored here is everything needed to serve it back and to
 * notice if it ever stopped matching: its {@see $checksum} is taken at upload
 * and never recomputed, so a file replaced underneath the application can be
 * told from one that was not.
 *
 * Attachments follow their entry's own rule about being written on: an entry
 * the books have been shut on can neither gain one nor lose one, which is what
 * makes the seal mean anything.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\EntryAttachmentTest
 */
#[ORM\Table(name: EntryAttachment::TABLE_NAME)]
#[ORM\Index(name: 'idx_attachment_entry', columns: ['entry_id'])]
#[ORM\Entity(repositoryClass: EntryAttachmentRepository::class)]
class EntryAttachment
{
    final public const string TABLE_NAME = 'accounting_entry_attachments';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: LedgerEntry::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'entry_id', nullable: false, onDelete: 'CASCADE')]
    private LedgerEntry $entry;

    /**
     * What the file was called when it was uploaded, shown on screen and sent
     * back on download. Never used to build a path: a name chosen by whoever
     * produced the document has no business deciding where it is written.
     */
    #[ORM\Column(name: 'filename', type: Types::STRING, length: 255)]
    private string $filename;

    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 127)]
    private string $mimeType;

    /** In bytes. */
    #[ORM\Column(name: 'size', type: Types::INTEGER)]
    private int $size;

    /**
     * SHA-256 of the file as it was received, in hex.
     *
     * Taken once and never recomputed. It is what lets a stored document be
     * compared against the one that was uploaded — and, incidentally, what
     * would show the same invoice attached twice.
     */
    #[ORM\Column(name: 'checksum', type: Types::STRING, length: 64)]
    private string $checksum;

    /**
     * Where the file sits under the storage root, as the storage wrote it.
     *
     * Stored rather than derived, so the layout can change for new uploads
     * without orphaning everything written under the old one.
     */
    #[ORM\Column(name: 'storage_path', type: Types::STRING, length: 255)]
    private string $storagePath;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getEntry(): LedgerEntry
    {
        return $this->entry;
    }

    public function setEntry(LedgerEntry $entry): self
    {
        $this->entry = $entry;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function setChecksum(string $checksum): self
    {
        $this->checksum = $checksum;

        return $this;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): self
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getUploadedAt(): ?DateTimeImmutable
    {
        return $this->created;
    }
}
