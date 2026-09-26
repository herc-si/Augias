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

namespace Augias\UserBundle\Security;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyPermission;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Repository\MembershipRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The role of whoever is signed in, in the company they have open.
 *
 * Read once per request: the voter asks it for every button a page draws.
 * Null when there is no one, no open company, or no membership in it — none
 * of which grants anything.
 */
final class CompanyAccess implements ResetInterface
{
    /** @var array<string, CompanyRole|null> */
    private array $roles = [];

    public function __construct(
        private readonly Security $security,
        private readonly CompanySelector $companySelector,
        private readonly MembershipRepository $memberships,
    ) {
    }

    public function role(): ?CompanyRole
    {
        $user = $this->security->getUser();
        $companyId = $this->companySelector->getCompany();

        if (! $user instanceof User || ! $companyId instanceof Ulid || ! $user->getId() instanceof Ulid) {
            return null;
        }

        $key = $user->getId()->toBase32() . '|' . $companyId->toBase32();

        if (! array_key_exists($key, $this->roles)) {
            $membership = $this->memberships->findOne($user, $companyId);
            $this->roles[$key] = $membership instanceof Membership ? $membership->getRole() : null;
        }

        return $this->roles[$key];
    }

    /**
     * The signed-in member's own membership of the open company.
     */
    public function membership(): ?Membership
    {
        $user = $this->security->getUser();
        $companyId = $this->companySelector->getCompany();

        if (! $user instanceof User || ! $companyId instanceof Ulid) {
            return null;
        }

        return $this->memberships->findOne($user, $companyId);
    }

    public function can(CompanyPermission $permission): bool
    {
        return $this->role()?->can($permission) ?? false;
    }

    public function reset(): void
    {
        $this->roles = [];
    }
}
