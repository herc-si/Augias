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
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\ElectronicInvoicingBundle\Enum\ReportKind;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One sale or one payment reported to the platform for e-reporting — kept so
 * that nothing is reported twice, and so a failed attempt is tried again.
 *
 * The source is the invoice for a transaction, the payment for a payment.
 */
#[ORM\Entity(repositoryClass: ElectronicReportRepository::class)]
#[ORM\Table(name: ElectronicReport::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'unique_report_source', columns: ['company_id', 'kind', 'source_id'])]
class ElectronicReport
{
    use CompanyAware;
    use TimeStampable;

    public const string TABLE_NAME = 'einvoicing_report';

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ReportKind::class)]
    private ReportKind $kind;

    #[ORM\Column(name: 'source_id', type: UlidType::NAME)]
    private Ulid $sourceId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $provider;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $success = false;

    /** The platform's ids for what it stored, comma-separated. */
    #[ORM\Column(name: 'external_reference', type: Types::STRING, length: 255, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    public function __construct(ReportKind $kind, Ulid $sourceId, string $provider)
    {
        $this->kind = $kind;
        $this->sourceId = $sourceId;
        $this->provider = $provider;
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getKind(): ReportKind
    {
        return $this->kind;
    }

    public function getSourceId(): Ulid
    {
        return $this->sourceId;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function succeeded(?string $externalReference): self
    {
        $this->success = true;
        $this->externalReference = $externalReference;
        $this->message = null;

        return $this;
    }

    public function failed(string $message): self
    {
        $this->success = false;
        $this->message = $message;

        return $this;
    }
}
