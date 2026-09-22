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

use Augias\CoreBundle\Enum\AccessReason;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A record that someone running this deployment looked at a company's data.
 *
 * Whoever operates a hosted Augias can read across companies — that is what
 * operating it means. This is the account of when they did, and it belongs to
 * the company that was read: the question it answers is "who opened my file
 * last March", and it is the customer asking, even though it is the operator
 * who reads the page.
 *
 * Which is why it lives here rather than wherever the reading happens. The
 * table, the fixed list of reasons and the migration are in the open-source
 * application, so the shape of the promise can be checked by the people it is
 * made to, and the rows leave with them in a company export like everything
 * else that is theirs. What is *not* here is a screen: the operator console
 * shows this journal, and a customer is answered from it on request.
 *
 * Nothing in this repository writes to it. A self-hosted install has no
 * operator but its owner, so the table stays empty, and an empty account of
 * access is the true one.
 *
 * `CompanyAware`, so the company filter scopes it without anyone remembering
 * to. Operator tooling reads and writes across companies with that filter
 * disabled, deliberately, as it already must for every other table. It also
 * means the record dies with the company, which is right — once there is
 * nobody left to ask, there is nothing left to keep.
 */
#[ORM\Table(name: OperatorAccess::TABLE_NAME)]
#[ORM\Index(name: 'operator_access_company_time', columns: ['company_id', 'accessed_at'])]
#[ORM\Entity]
class OperatorAccess
{
    final public const string TABLE_NAME = 'operator_access';

    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    /**
     * Why, as the machine value of an {@see AccessReason}.
     *
     * Text rather than an enum-typed column on purpose: the tooling that
     * writes these can be a version ahead of the application, and a value this
     * build has never heard of has to survive being read back. Hydration would
     * throw on an enum column; here it is shown as written.
     */
    #[ORM\Column(name: 'reason', type: Types::STRING, length: 255)]
    private readonly string $reason;

    public function __construct(
        Company $company,
        /**
         * Who looked, as they identify themselves to this application — an
         * email address today. Stored as text rather than as a link to a user,
         * because the record has to outlive the account: an operator who has
         * left is exactly the one a customer might ask about.
         */
        #[ORM\Column(name: 'operator', type: Types::STRING, length: 255)]
        private readonly string $operator,
        AccessReason $reason,
        #[ORM\Column(name: 'accessed_at', type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $accessedAt,
    ) {
        $this->company = $company;
        $this->reason = $reason->value;
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * The reason as this build understands it, or null for one it does not.
     */
    public function getReasonKind(): ?AccessReason
    {
        return AccessReason::tryFrom($this->reason);
    }

    public function getAccessedAt(): DateTimeImmutable
    {
        return $this->accessedAt;
    }
}
