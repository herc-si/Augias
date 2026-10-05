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
 * A company paid for by another one's subscription: an agency opens the
 * companies it manages under the plan it already pays for.
 *
 * The covered company keeps its own data; only the subscription is the host's.
 * Whether the cover still holds — the host's plan allowing that many companies
 * — is decided when the subscription is read, so a host that moves to a
 * smaller plan uncovers its latest companies without anything being rewritten.
 *
 * Neither side is named "company": the company filter scopes any entity with
 * that association to the open company, and the cover must be read from both.
 *
 * @see \Augias\SaasBundle\Subscription\CoveredSubscriptionProvider
 */
#[ORM\Table(name: CompanyCoverage::TABLE_NAME)]
#[ORM\Entity]
class CompanyCoverage
{
    final public const string TABLE_NAME = 'company_coverage';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Company::class)]
        #[ORM\JoinColumn(name: 'covered_id', referencedColumnName: 'id', unique: true, nullable: false, onDelete: 'CASCADE')]
        private Company $covered,
        #[ORM\ManyToOne(targetEntity: Company::class)]
        #[ORM\JoinColumn(name: 'host_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Company $host,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getCovered(): Company
    {
        return $this->covered;
    }

    public function getHost(): Company
    {
        return $this->host;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
