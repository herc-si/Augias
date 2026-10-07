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

namespace Augias\ElectronicInvoicingBundle\Entity;

use Augias\CoreBundle\Traits\Entity\CompanyAware;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One step of a sent invoice's life on the platform — deposited, made
 * available, approved, disputed, paid… — as the platform dated it.
 *
 * The submission keeps only the latest status; this keeps them all, so the
 * invoice shows what happened and when, not just where it ended up.
 */
#[ORM\Entity]
#[ORM\Table(name: ElectronicInvoiceSubmissionEvent::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'einvoicing_submission_event_once', columns: ['submission_id', 'provider_event_id'])]
class ElectronicInvoiceSubmissionEvent
{
    public const string TABLE_NAME = 'einvoicing_submission_event';

    use CompanyAware;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ElectronicInvoiceSubmission::class, inversedBy: 'events')]
        #[ORM\JoinColumn(name: 'submission_id', nullable: false, onDelete: 'CASCADE')]
        private ElectronicInvoiceSubmission $submission,
        /** The platform's own id for the event: what keeps a poll from recording it twice. */
        #[ORM\Column(name: 'provider_event_id', type: Types::STRING, length: 64)]
        private string $providerEventId,
        #[ORM\Column(name: 'status_code', type: Types::STRING, length: 64)]
        private string $statusCode,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $occurredAt,
        /** A reason code (MDT-113) and what the other party wrote, when they gave any. */
        #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
        private ?string $reason = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $note = null,
    ) {
        $this->company = $submission->getCompany();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getSubmission(): ElectronicInvoiceSubmission
    {
        return $this->submission;
    }

    public function getProviderEventId(): string
    {
        return $this->providerEventId;
    }

    public function getStatusCode(): string
    {
        return $this->statusCode;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
