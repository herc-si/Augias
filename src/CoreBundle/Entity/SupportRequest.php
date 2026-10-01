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

use Augias\CoreBundle\Enum\SupportRequestStatus;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A company asking the people who run the service to come and look.
 *
 * It is the company's written instruction, and the bounds of the visit: what
 * was asked, by whom, until when. Whoever takes it may open the company under
 * their own name — never as one of its members — and only while it runs. When
 * it is closed, the account of what was done stays on it, for the company to
 * read.
 *
 * People are kept as the address they signed in with rather than as links to
 * accounts, for the same reason as {@see OperatorAccess}: the record has to
 * outlive whoever is named in it.
 */
#[ORM\Table(name: SupportRequest::TABLE_NAME)]
#[ORM\Index(name: 'support_request_company_time', columns: ['company_id', 'requested_at'])]
#[ORM\Entity]
class SupportRequest
{
    final public const string TABLE_NAME = 'support_request';

    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, enumType: SupportRequestStatus::class)]
    private SupportRequestStatus $status = SupportRequestStatus::Pending;

    #[ORM\Column(name: 'operator', type: Types::STRING, length: 255, nullable: true)]
    private ?string $operator = null;

    #[ORM\Column(name: 'accepted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'ended_by', type: Types::STRING, length: 255, nullable: true)]
    private ?string $endedBy = null;

    /**
     * What was done, written by whoever took the request when they close it.
     */
    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct(
        Company $company,
        #[ORM\Column(name: 'requested_by', type: Types::STRING, length: 255)]
        private readonly string $requestedBy,
        #[ORM\Column(name: 'message', type: Types::TEXT)]
        private readonly string $message,
        #[ORM\Column(name: 'hours', type: Types::INTEGER)]
        private readonly int $hours,
        #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $requestedAt,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $expiresAt,
    ) {
        $this->company = $company;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getHours(): int
    {
        return $this->hours;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getStatus(): SupportRequestStatus
    {
        return $this->status;
    }

    public function getOperator(): ?string
    {
        return $this->operator;
    }

    public function getAcceptedAt(): ?DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getEndedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getEndedBy(): ?string
    {
        return $this->endedBy;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * Still asked for or taken, and not run out: the company's door is open.
     */
    public function isOpen(DateTimeImmutable $now): bool
    {
        return $this->status->isOpen() && ! $this->isExpired($now);
    }

    /**
     * Whether this person may be inside the company on this request, now.
     */
    public function admits(string $operator, DateTimeImmutable $now): bool
    {
        return SupportRequestStatus::Accepted === $this->status
            && $this->operator === $operator
            && ! $this->isExpired($now);
    }

    public function accept(string $operator, DateTimeImmutable $now): void
    {
        if (! $this->isOpen($now)) {
            throw new LogicException('Only an open request can be taken.');
        }

        // Taken twice by the same person is a second click, not a conflict;
        // taken by someone else, the first keeps it.
        if (SupportRequestStatus::Accepted === $this->status && $this->operator !== $operator) {
            throw new LogicException('This request has already been taken by someone else.');
        }

        if (SupportRequestStatus::Pending === $this->status) {
            $this->status = SupportRequestStatus::Accepted;
            $this->operator = $operator;
            $this->acceptedAt = $now;
        }
    }

    public function resolve(string $operator, string $note, DateTimeImmutable $now): void
    {
        if (! $this->status->isOpen()) {
            throw new LogicException('This request is already closed.');
        }

        if ('' === trim($note)) {
            throw new LogicException('Closing a request takes an account of what was done.');
        }

        $this->status = SupportRequestStatus::Resolved;
        $this->note = trim($note);
        $this->endedAt = $now;
        $this->endedBy = $operator;
    }

    public function revoke(string $member, DateTimeImmutable $now): void
    {
        if (! $this->status->isOpen()) {
            throw new LogicException('This request is already closed.');
        }

        $this->status = SupportRequestStatus::Revoked;
        $this->endedAt = $now;
        $this->endedBy = $member;
    }
}
