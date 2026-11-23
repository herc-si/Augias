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

namespace Augias\UserBundle\Entity;

use Augias\UserBundle\Repository\TrustedDeviceRepository;
use Augias\UserBundle\Security\DeviceName;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function mb_substr;

/**
 * A browser trusted to skip the second factor, so its holder can see it and
 * take it back (Rémi, 23/11/2026).
 *
 * The trust itself still lives in scheb's signed cookie; that cookie only
 * says "this account, this firewall, this version", which is why nothing
 * could be listed before. Each device now has its own `key`, written into
 * the cookie as its version: the cookie is matched to its row, a row
 * removed is a cookie no longer honoured, and the row says where and when
 * the device was last used.
 *
 * Not `CompanyAware`, like the sign-in journal: a device belongs to the
 * account, and is used before any company is in scope.
 */
#[ORM\Table(name: TrustedDevice::TABLE_NAME)]
#[ORM\Index(name: 'trusted_device_user', columns: ['user_id'])]
#[ORM\Entity(repositoryClass: TrustedDeviceRepository::class)]
class TrustedDevice
{
    final public const string TABLE_NAME = 'user_trusted_devices';

    private const int USER_AGENT_LENGTH = 255;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    #[ORM\Column(name: 'user_agent', type: Types::STRING, length: self::USER_AGENT_LENGTH, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastUsedAt;

    #[ORM\Column(name: 'last_ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $lastIpAddress;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private readonly User $user,
        /**
         * The version written in this device's cookie. Not a secret — the
         * cookie is signed — only a way to tell one device from another.
         */
        #[ORM\Column(name: 'device_key', type: Types::INTEGER)]
        private readonly int $key,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $expiresAt,
        ?string $userAgent,
        #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
        private readonly ?string $ipAddress,
    ) {
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_LENGTH);
        $this->lastUsedAt = $createdAt;
        $this->lastIpAddress = $ipAddress;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKey(): int
    {
        return $this->key;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * "Firefox · Linux", or null when the user agent says nothing usable.
     */
    public function getName(): ?string
    {
        return DeviceName::of($this->userAgent);
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastIpAddress(): ?string
    {
        return $this->lastIpAddress;
    }

    public function usedAt(DateTimeImmutable $when, ?string $ipAddress): void
    {
        $this->lastUsedAt = $when;
        $this->lastIpAddress = $ipAddress;
    }
}
