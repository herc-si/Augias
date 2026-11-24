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

namespace Augias\McpBundle\Entity;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\McpBundle\Enum\AssistantEventKind;
use Augias\McpBundle\Repository\AssistantEventRepository;
use Augias\UserBundle\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One thing the built-in assistant did: a question asked, a tool run, a
 * change proposed, confirmed or cancelled. Who, when and which tool; never
 * what was asked nor answered, which the assistant does not keep.
 *
 * The questions of a month are what the plan's quota counts. Kept for a
 * year (AssistantJournalRetention), `CompanyAware` so they leave with the
 * company.
 */
#[ORM\Table(name: AssistantEvent::TABLE_NAME)]
#[ORM\Index(name: 'assistant_events_company_kind_time', columns: ['company_id', 'kind', 'occurred_at'])]
#[ORM\Index(name: 'assistant_events_time', columns: ['occurred_at'])]
#[ORM\Entity(repositoryClass: AssistantEventRepository::class)]
class AssistantEvent
{
    final public const string TABLE_NAME = 'assistant_events';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    use CompanyAware;

    public function __construct(
        Company $company,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private readonly ?User $user,
        #[ORM\Column(name: 'kind', type: Types::STRING, length: 16, enumType: AssistantEventKind::class)]
        private readonly AssistantEventKind $kind,
        #[ORM\Column(name: 'tool', type: Types::STRING, length: 64, nullable: true)]
        private readonly ?string $tool,
        #[ORM\Column(name: 'failed', type: Types::BOOLEAN, options: ['default' => false])]
        private readonly bool $failed,
        #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $occurredAt,
    ) {
        $this->company = $company;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getKind(): AssistantEventKind
    {
        return $this->kind;
    }

    public function getTool(): ?string
    {
        return $this->tool;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
