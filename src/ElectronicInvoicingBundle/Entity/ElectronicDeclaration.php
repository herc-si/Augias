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
use Augias\ElectronicInvoicingBundle\Repository\ElectronicDeclarationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function in_array;

/**
 * An e-reporting declaration the platform sent the tax administration: one
 * period's sales, or its payments, built from what Augias reported. Copied
 * here every hour so the company sees it, and is told when it is rejected.
 */
#[ORM\Entity(repositoryClass: ElectronicDeclarationRepository::class)]
#[ORM\Table(name: ElectronicDeclaration::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'unique_declaration', columns: ['company_id', 'provider', 'external_id'])]
class ElectronicDeclaration
{
    use CompanyAware;
    use TimeStampable;

    public const string TABLE_NAME = 'einvoicing_declaration';

    /** The tax administration refused it, or could not read it. */
    public const array REJECTED_STATUSES = ['ppf:ereporting-rejected', 'ppf:ereporting-ack-error'];

    /** The tax administration took it. */
    public const array ACCEPTED_STATUSES = ['ppf:ereporting-ack', 'ppf:ereporting-response-ok'];

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $provider;

    #[ORM\Column(name: 'external_id', type: Types::STRING, length: 64)]
    private string $externalId;

    /** 'transaction' for sales, 'payment' for payments. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $kind;

    #[ORM\Column(name: 'period_start', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'period_end', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodEnd;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(name: 'status_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $statusAt = null;

    public function __construct(string $provider, string $externalId, string $kind, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd)
    {
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->kind = $kind;
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getPeriodStart(): DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getStatusAt(): ?DateTimeImmutable
    {
        return $this->statusAt;
    }

    public function isRejected(): bool
    {
        return in_array($this->status, self::REJECTED_STATUSES, true);
    }

    public function isAccepted(): bool
    {
        return in_array($this->status, self::ACCEPTED_STATUSES, true);
    }

    /**
     * @return bool whether the status changed
     */
    public function updateStatus(?string $status, ?DateTimeImmutable $at): bool
    {
        if ($status === $this->status) {
            return false;
        }

        $this->status = $status;
        $this->statusAt = $at;

        return true;
    }
}
