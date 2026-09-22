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

use Augias\CoreBundle\Enum\RecordKind;
use Augias\CoreBundle\Repository\RecordAccessRepository;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\UserBundle\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function mb_substr;

/**
 * One record, opened once, by one person.
 *
 * The journal exists to answer "where have I been" — which invoice did I look
 * at last Tuesday, did I open that client's file before the call. Nothing in
 * the application could answer it: the pages leave no trace, and the server
 * log is both unreadable and gone within the month.
 *
 * The label is a copy, not a link. An invoice can be deleted and a client
 * renamed; a journal that changed its account of the past along with them
 * would be worth less than one that says what was on the screen that day. The
 * id is kept beside it so the line can still lead back to the record while it
 * exists.
 *
 * `CompanyAware`, so the filter scopes it and the rows leave with the company
 * in an export. Scoping by company is not enough on its own, though: this is
 * *your* trail, not the company's, so every query also names the user — see
 * the repository.
 */
#[ORM\Table(name: RecordAccess::TABLE_NAME)]
#[ORM\Index(name: 'record_access_user_time', columns: ['company_id', 'user_id', 'opened_at'])]
#[ORM\Index(name: 'record_access_time', columns: ['opened_at'])]
#[ORM\Entity(repositoryClass: RecordAccessRepository::class)]
class RecordAccess
{
    final public const string TABLE_NAME = 'record_access';

    private const int LABEL_LENGTH = 255;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    use CompanyAware;

    #[ORM\Column(name: 'label', type: Types::STRING, length: self::LABEL_LENGTH)]
    private string $label;

    public function __construct(
        Company $company,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private readonly User $user,
        #[ORM\Column(name: 'kind', type: Types::STRING, length: 32, enumType: RecordKind::class)]
        private readonly RecordKind $kind,
        #[ORM\Column(name: 'record_id', type: UlidType::NAME)]
        private readonly Ulid $recordId,
        string $label,
        #[ORM\Column(name: 'opened_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $openedAt,
    ) {
        $this->company = $company;
        $this->label = mb_substr($label, 0, self::LABEL_LENGTH);
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKind(): RecordKind
    {
        return $this->kind;
    }

    public function getRecordId(): Ulid
    {
        return $this->recordId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getOpenedAt(): DateTimeImmutable
    {
        return $this->openedAt;
    }
}
