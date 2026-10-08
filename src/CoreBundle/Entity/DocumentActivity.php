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

use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Enum\RecordKind;
use Augias\CoreBundle\Repository\DocumentActivityRepository;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\UserBundle\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function array_values;
use function mb_substr;
use function preg_match;

/**
 * One line in a document's history: sent by email to whom, opened by the
 * client when, moved from one status to another by whom.
 *
 * The document is named by its kind and id, as in the journal of opened
 * records, rather than by three foreign keys of which two are always empty.
 */
#[ORM\Table(name: DocumentActivity::TABLE_NAME)]
#[ORM\Index(name: 'document_activity_document', columns: ['company_id', 'kind', 'record_id', 'occurred_at'])]
#[ORM\Entity(repositoryClass: DocumentActivityRepository::class)]
class DocumentActivity
{
    final public const string TABLE_NAME = 'document_activity';

    private const int TEXT_LENGTH = 255;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    use CompanyAware;

    /**
     * The transition for a status change, the reason for a failed send, the
     * kind of reminder for a reminder.
     */
    #[ORM\Column(name: 'detail', type: Types::STRING, length: self::TEXT_LENGTH, nullable: true)]
    private ?string $detail;

    /**
     * Who opened the client's link, as far as the browser says: a mail
     * scanner that follows every link is not the client reading the quote.
     */
    #[ORM\Column(name: 'user_agent', type: Types::STRING, length: self::TEXT_LENGTH, nullable: true)]
    private ?string $userAgent;

    /**
     * Where an acceptance or a refusal came from: with the name typed and the
     * time, what is kept as the client's word. Not kept for a mere visit.
     */
    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress;

    /**
     * The document as the client accepted it, kept beside the answer: where
     * it is stored, and its SHA-256, which says it has not changed since.
     */
    #[ORM\Column(name: 'proof_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $proofPath = null;

    #[ORM\Column(name: 'proof_sha256', type: Types::STRING, length: 64, nullable: true)]
    private ?string $proofSha256 = null;

    /**
     * @param list<string> $recipients
     */
    public function __construct(
        Company $company,
        #[ORM\Column(name: 'kind', type: Types::STRING, length: 32, enumType: RecordKind::class)]
        private readonly RecordKind $kind,
        #[ORM\Column(name: 'record_id', type: UlidType::NAME)]
        private readonly Ulid $recordId,
        #[ORM\Column(name: 'type', type: Types::STRING, length: 32, enumType: DocumentActivityType::class)]
        private readonly DocumentActivityType $type,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $occurredAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private readonly ?User $user = null,
        ?string $detail = null,
        #[ORM\Column(name: 'recipients', type: Types::JSON, nullable: true)]
        private readonly ?array $recipients = null,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ) {
        $this->company = $company;
        $this->detail = null === $detail ? null : mb_substr($detail, 0, self::TEXT_LENGTH);
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, self::TEXT_LENGTH);
        $this->ipAddress = $ipAddress;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getKind(): RecordKind
    {
        return $this->kind;
    }

    public function getRecordId(): Ulid
    {
        return $this->recordId;
    }

    public function getType(): DocumentActivityType
    {
        return $this->type;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * @return list<string>
     */
    public function getRecipients(): array
    {
        return array_values($this->recipients ?? []);
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getProofPath(): ?string
    {
        return $this->proofPath;
    }

    public function getProofSha256(): ?string
    {
        return $this->proofSha256;
    }

    /**
     * A visit that was most likely a program and not a person: no browser
     * named, or one that says it is a robot, a link checker or a preview.
     * Mail filters open every link of an incoming message to check it, so a
     * "viewed" a few seconds after sending is often one of them.
     */
    public function isLikelyAutomated(): bool
    {
        if (! $this->type->canBeAutomated()) {
            return false;
        }

        if (null === $this->userAgent || '' === $this->userAgent) {
            return true;
        }

        return 1 === preg_match('/bot|crawl|spider|preview|scan|check|fetch|python|curl|wget|headless|go-http|java\/|okhttp|libwww|safelinks|proofpoint|mimecast|barracuda/i', $this->userAgent);
    }
}
