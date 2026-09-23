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

namespace Augias\InvoiceBundle\Entity;

use Augias\CoreBundle\Storage\StoredDocument;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\InvoiceBundle\Repository\DisbursementReceiptRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The supplier's invoice behind a disbursement.
 *
 * A disbursement is only outside turnover if the supplier's document was made
 * out to the client and handed over with the invoice that re-bills it (CGI art.
 * 267-II-2°). Without it the advance is an ordinary sale, whatever the line
 * says. So the document belongs to the line it justifies — not to the book
 * entry, which leaves the disbursement out and of which an invoice paid in
 * instalments has several, and not to the invoice as a whole, where two
 * advances would have no way to tell their receipts apart.
 *
 * The client sees it: it is listed on their copy of the invoice and travels
 * with the invoice e-mail.
 */
#[ORM\Table(name: DisbursementReceipt::TABLE_NAME)]
#[ORM\Index(name: 'idx_disbursement_receipt_line', columns: ['line_id'])]
#[ORM\Entity(repositoryClass: DisbursementReceiptRepository::class)]
class DisbursementReceipt
{
    final public const string TABLE_NAME = 'invoice_disbursement_receipts';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: Line::class, inversedBy: 'receipts')]
    #[ORM\JoinColumn(name: 'line_id', nullable: false, onDelete: 'CASCADE')]
    private Line $line;

    /**
     * The name the file had when it was uploaded — a label, never a path.
     */
    #[ORM\Column(name: 'filename', type: Types::STRING, length: 255)]
    private string $filename;

    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 127)]
    private string $mimeType;

    /** In bytes. */
    #[ORM\Column(name: 'size', type: Types::INTEGER)]
    private int $size;

    /** SHA-256 of the file as it was received, in hex. */
    #[ORM\Column(name: 'checksum', type: Types::STRING, length: 64)]
    private string $checksum;

    #[ORM\Column(name: 'storage_path', type: Types::STRING, length: 255)]
    private string $storagePath;

    public static function of(StoredDocument $document): self
    {
        $receipt = new self();
        $receipt->filename = $document->filename;
        $receipt->mimeType = $document->mimeType;
        $receipt->size = $document->size;
        $receipt->checksum = $document->checksum;
        $receipt->storagePath = $document->storagePath;

        return $receipt;
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getLine(): Line
    {
        return $this->line;
    }

    public function setLine(Line $line): self
    {
        $this->line = $line;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getUploadedAt(): ?DateTimeImmutable
    {
        return $this->created;
    }
}
