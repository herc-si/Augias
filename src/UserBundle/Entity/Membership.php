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

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Export\Attribute\ExportIgnore;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Repository\MembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Someone's place in a company: that they belong to it, and in what role.
 *
 * The table is the one the plain user–company link always used; the role is
 * the column that was missing. Keeping it — its name, its two-column key — is
 * what lets the company filter, which reads it in SQL, and every caller of
 * User::getCompanies() carry on unchanged.
 */
#[ORM\Table(name: Membership::TABLE_NAME)]
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ExportIgnore]
class Membership
{
    final public const string TABLE_NAME = 'user_company';

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'memberships')]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private readonly User $user,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'memberships')]
        #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Company $company,
        #[ORM\Column(name: 'role', type: Types::STRING, length: 20, enumType: CompanyRole::class, options: ['default' => 'admin'])]
        private CompanyRole $role,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getRole(): CompanyRole
    {
        return $this->role;
    }

    public function setRole(CompanyRole $role): self
    {
        $this->role = $role;

        return $this;
    }
}
