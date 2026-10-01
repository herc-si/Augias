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

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * Whether the people running a hosted deployment take requests for help, and
 * on what terms. One row for the whole deployment, not one per company.
 *
 * The application only reads it. Whatever operates the deployment writes it,
 * which is why nothing here names that operator: the name shown to customers
 * is whatever was saved, and with no row at all the feature is off. A
 * self-hosted install never has a row.
 */
#[ORM\Table(name: SupportSettings::TABLE_NAME)]
#[ORM\Entity]
class SupportSettings
{
    final public const string TABLE_NAME = 'support_settings';

    /**
     * The longest a company may leave its door open, in hours, when nothing
     * else was decided: a week. Beyond that an "intervention" is standing
     * access, which is precisely what this is meant not to be.
     */
    final public const int DEFAULT_MAX_HOURS = 168;

    /**
     * The spans a company is offered, in hours, before the maximum trims them.
     */
    final public const array DURATIONS = [1, 24, 72, 168];

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN)]
    private bool $enabled = false;

    /**
     * Who customers are told they are letting in. Null falls back to a
     * neutral wording rather than to anybody's name.
     */
    #[ORM\Column(name: 'provider_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $providerName = null;

    #[ORM\Column(name: 'max_hours', type: Types::INTEGER)]
    private int $maxHours = self::DEFAULT_MAX_HOURS;

    /**
     * Where a new request is announced. Null: nobody is written to, and
     * requests are found by looking.
     */
    #[ORM\Column(name: 'notify_email', type: Types::STRING, length: 255, nullable: true)]
    private ?string $notifyEmail = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    public function setProviderName(?string $providerName): self
    {
        $providerName = null === $providerName ? null : trim($providerName);
        $this->providerName = '' === $providerName ? null : $providerName;

        return $this;
    }

    public function getMaxHours(): int
    {
        return $this->maxHours;
    }

    public function setMaxHours(int $maxHours): self
    {
        $this->maxHours = max(1, $maxHours);

        return $this;
    }

    /**
     * The spans offered to a company: the usual ones up to the maximum, and the
     * maximum itself when it falls between them.
     *
     * @return list<int>
     */
    public function getDurations(): array
    {
        $durations = array_values(array_filter(self::DURATIONS, fn (int $hours): bool => $hours <= $this->maxHours));

        if (! in_array($this->maxHours, $durations, true)) {
            $durations[] = $this->maxHours;
        }

        return $durations;
    }

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(?string $notifyEmail): self
    {
        $notifyEmail = null === $notifyEmail ? null : trim($notifyEmail);
        $this->notifyEmail = '' === $notifyEmail ? null : $notifyEmail;

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
