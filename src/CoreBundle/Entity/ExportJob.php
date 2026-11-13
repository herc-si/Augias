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

namespace Augias\CoreBundle\Entity;

use Augias\CoreBundle\Export\Attribute\ExportIgnore;
use Augias\CoreBundle\Export\Enum\ExportFormat;
use Augias\CoreBundle\Export\Enum\ExportPurpose;
use Augias\CoreBundle\Export\Enum\ExportStatus;
use Augias\CoreBundle\Repository\ExportJobRepository;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

#[ORM\Table(name: ExportJob::TABLE_NAME)]
#[ORM\Index(columns: ['company_id'])]
#[ORM\Index(columns: ['requested_by'])]
#[ORM\Index(columns: ['status'])]
#[ORM\Entity(repositoryClass: ExportJobRepository::class)]
#[ExportIgnore]
class ExportJob
{
    use CompanyAware;

    final public const string TABLE_NAME = 'export_jobs';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    private Ulid $id;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, enumType: ExportStatus::class)]
    private ExportStatus $status = ExportStatus::Pending;

    /**
     * Relative path from the project root, e.g. `var/exports/{companyId58}/{jobId58}.zip`.
     */
    #[ORM\Column(name: 'archive_path', type: Types::STRING, length: 512, nullable: true)]
    private ?string $archivePath = null;

    /**
     * Size of the generated archive in bytes. The column uses INTEGER (signed
     * 32-bit, ~2.1 GB) because Doctrine's BIGINT type returns a string on MySQL
     * and would break the `?int` PHP property. ZIP archives produced by the
     * exporter are well under this cap; if/when that changes, widen both the
     * column type and the PHP property in tandem.
     */
    #[ORM\Column(name: 'file_size', type: Types::INTEGER, nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'failure_reason', type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    /**
     * Asked for by someone, or made because the company is closing.
     */
    #[ORM\Column(name: 'purpose', type: Types::STRING, length: 20, enumType: ExportPurpose::class, options: ['default' => 'requested'])]
    private ExportPurpose $purpose = ExportPurpose::Requested;

    /**
     * When the archive was first taken — the trace that what a closing
     * company has to keep did reach its people.
     */
    #[ORM\Column(name: 'downloaded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $downloadedAt = null;

    public function __construct(
        /**
         * Stored as a bare ULID rather than an `#[ORM\ManyToOne]` to `User` on purpose.
         * The export feature lives in CoreBundle, and depending on UserBundle from here
         * would invert the dependency direction used elsewhere in the codebase. The
         * `requested_by` FK constraint in the migration (`ON DELETE CASCADE`) still
         * guarantees orphan cleanup at the database level.
         */
        #[ORM\Column(name: 'requested_by', type: UlidType::NAME)]
        private Ulid $requestedBy,
        #[ORM\Column(name: 'format', type: Types::STRING, length: 10, enumType: ExportFormat::class)]
        private ExportFormat $format
    ) {
        $this->id = new Ulid();
        $this->createdAt = CarbonImmutable::now();
    }

    /**
     * The archive of a company that is closing, made without anyone asking.
     *
     * @param Ulid $onBehalfOf one of the people who run the company
     */
    public static function forClosure(Ulid $onBehalfOf): self
    {
        $job = new self($onBehalfOf, ExportFormat::Csv);
        $job->purpose = ExportPurpose::Closure;

        return $job;
    }

    public function getPurpose(): ExportPurpose
    {
        return $this->purpose;
    }

    public function isForClosure(): bool
    {
        return ExportPurpose::Closure === $this->purpose;
    }

    public function getDownloadedAt(): ?DateTimeImmutable
    {
        return $this->downloadedAt;
    }

    /**
     * Keeps the first time only: that it was taken at all is what matters.
     */
    public function markDownloaded(): void
    {
        $this->downloadedAt ??= CarbonImmutable::now();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getRequestedBy(): Ulid
    {
        return $this->requestedBy;
    }

    public function getFormat(): ExportFormat
    {
        return $this->format;
    }

    public function getStatus(): ExportStatus
    {
        return $this->status;
    }

    public function getArchivePath(): ?string
    {
        return $this->archivePath;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function markProcessing(): void
    {
        $this->status = ExportStatus::Processing;
    }

    public function markCompleted(string $archivePath, int $fileSize): void
    {
        $this->status = ExportStatus::Completed;
        $this->archivePath = $archivePath;
        $this->fileSize = $fileSize;
        $this->completedAt = CarbonImmutable::now();
    }

    public function markFailed(string $reason): void
    {
        $this->status = ExportStatus::Failed;
        $this->failureReason = $reason;
        $this->completedAt = CarbonImmutable::now();
    }

    public function resolveAbsolutePath(string $projectDir): ?string
    {
        if ($this->archivePath === null) {
            return null;
        }

        return rtrim($projectDir, '/') . '/' . ltrim($this->archivePath, '/');
    }
}
