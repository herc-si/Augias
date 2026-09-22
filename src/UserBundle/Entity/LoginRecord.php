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

use Augias\UserBundle\Enum\LoginOutcome;
use Augias\UserBundle\Repository\LoginRecordRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function mb_substr;

/**
 * One attempt to sign in to an account, kept so the account holder can look.
 *
 * `last_login` on the user answered "when did I last sign in" and nothing
 * else. The questions people actually ask are "was that me on Tuesday", "is
 * someone trying my password", and — for whoever runs a company — "who has
 * been getting into our books". None of them can be answered by a single
 * column, and none of them can be answered by an application log, which has
 * rotated away by the time anyone thinks to ask.
 *
 * Deliberately **not** `CompanyAware`. Signing in happens before any company
 * is in scope, and a person may belong to several; the row belongs to the
 * account, not to a tenant. Company scoping is still automatic where it
 * matters, because every tenant-facing query joins the user and the `company`
 * filter constrains `User` through its membership table. A failed attempt on
 * an address nobody owns therefore has no user, no company, and is visible
 * only to whoever operates the deployment — which is the right audience for
 * it.
 *
 * Rows are kept for 90 days and then deleted; see the purge command. The
 * journal is a security tool with a short memory on purpose: long enough to
 * investigate, short enough that it does not become a permanent record of
 * where someone was and on what device.
 */
#[ORM\Table(name: LoginRecord::TABLE_NAME)]
#[ORM\Index(name: 'login_record_user_time', columns: ['user_id', 'occurred_at'])]
#[ORM\Index(name: 'login_record_time', columns: ['occurred_at'])]
#[ORM\Entity(repositoryClass: LoginRecordRepository::class)]
class LoginRecord
{
    final public const string TABLE_NAME = 'login_records';

    /**
     * Long enough for the longest real user agent to stay recognisable, short
     * enough that a crafted one cannot fill the table. Truncated rather than
     * rejected: a record of an attempt matters more than the string that came
     * with it.
     */
    private const int USER_AGENT_LENGTH = 255;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    /**
     * The account, when there is one.
     *
     * Null for an attempt on an address that belongs to nobody. The row is
     * still worth keeping — a run of them is what someone guessing looks
     * like — but it is nobody's to read but the operator's.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    public function __construct(
        ?User $user,
        /**
         * What was typed in the username field, kept even when it matched an
         * account: an attempt on `admin@` tells a different story from one on
         * the owner's real address, and the user link alone would lose that.
         */
        #[ORM\Column(name: 'identifier', type: Types::STRING, length: 255)]
        private readonly string $identifier,
        #[ORM\Column(name: 'outcome', type: Types::STRING, length: 32, enumType: LoginOutcome::class)]
        private readonly LoginOutcome $outcome,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $occurredAt,
        /**
         * IPv6 fits in 45 characters, and a proxy's list of them does not —
         * what is stored is the client address Symfony resolved, one value.
         */
        #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
        private readonly ?string $ipAddress = null,
        #[ORM\Column(name: 'user_agent', type: Types::STRING, length: self::USER_AGENT_LENGTH, nullable: true)]
        private ?string $userAgent = null,
    ) {
        $this->user = $user;
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_LENGTH);
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getOutcome(): LoginOutcome
    {
        return $this->outcome;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
