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

use Augias\CoreBundle\Export\Attribute\ExportIgnore;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's configuration for one electronic-invoicing platform. A company
 * may keep several configured (e.g. to try a new one before switching), but
 * only one may be $active at a time — that is the one InvoiceBundle's "send
 * electronic invoice" action dispatches to.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Entity\ElectronicInvoiceProviderSettingTest
 */
#[ORM\Entity(repositoryClass: ElectronicInvoiceProviderSettingRepository::class)]
#[ORM\Table(name: ElectronicInvoiceProviderSetting::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'unique_name_company', columns: ['name', 'company_id'])]
#[UniqueEntity(fields: ['name', 'company'], message: 'einvoicing.constraint.provider_setting.unique_name')]
#[ORM\AssociationOverrides([new ORM\AssociationOverride(name: 'company', inversedBy: 'electronicInvoiceProviderSettings')])]
class ElectronicInvoiceProviderSetting implements Stringable
{
    public const string TABLE_NAME = 'einvoicing_provider_setting';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    private string $provider;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    #[ExportIgnore]
    private array $settings = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = false;

    /**
     * What the platform last said about the account's identity, for a provider
     * that can be asked. Null until it has been: a provider that cannot be
     * asked, or one configured before the question existed.
     *
     * A platform that has not verified the company refuses everything, so an
     * active setting it has not verified is treated as off — see
     * {@see self::isUsable()} — and comes back on its own once it is.
     */
    #[ORM\Column(name: 'account_verification', type: Types::STRING, length: 20, nullable: true, enumType: AccountVerification::class)]
    private ?AccountVerification $accountVerification = null;

    #[ORM\Column(name: 'account_checked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $accountCheckedAt = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name ?? '';
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider ?? '';
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getAccountVerification(): ?AccountVerification
    {
        return $this->accountVerification;
    }

    public function getAccountCheckedAt(): ?DateTimeImmutable
    {
        return $this->accountCheckedAt;
    }

    public function recordAccountCheck(AccountVerification $verification, DateTimeImmutable $checkedAt): self
    {
        $this->accountVerification = $verification;
        $this->accountCheckedAt = $checkedAt;

        return $this;
    }

    /**
     * Whether invoices can actually go through it: active, and not known to
     * be refused by the platform.
     */
    public function isUsable(): bool
    {
        return $this->active
            && (null === $this->accountVerification || $this->accountVerification->isUsable());
    }

    /**
     * Active by choice, but stopped because the platform has not verified the
     * account — what the configuration page has to explain.
     */
    public function isHeldByThePlatform(): bool
    {
        return $this->active && ! $this->isUsable();
    }

    public function __toString(): string
    {
        return $this->getName();
    }
}
