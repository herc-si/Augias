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
use function array_key_exists;
use function in_array;

/**
 * The role of whoever is signed in, in the company they have open.
 *
 * Read once per request: the voter asks it for every button a page draws.
 * Null when there is no one, no open company, or no membership in it — none
 * of which grants anything. A company scheduled for closure is read-only for
 * everyone in it, whatever their role.
 */
final class CompanyAccess implements ResetInterface
{
    /**
     * What a company being closed still allows: reading, taking the data out,
     * and the owner calling the closure off.
     */
    private const array WHILE_CLOSING = [
        CompanyPermission::BillingRead,
        CompanyPermission::Export,
        CompanyPermission::CloseCompany,
    ];

    /** @var array<string, Membership|null> */
    private array $memberships = [];

    public function __construct(
        private readonly Security $security,
        private readonly CompanySelector $companySelector,
        private readonly MembershipRepository $repository,
    ) {
    }

    public function role(): ?CompanyRole
    {
        return $this->membership()?->getRole();
    }

    /**
     * The signed-in member's own membership of the open company.
     */
    public function membership(): ?Membership
    {
        $user = $this->security->getUser();
        $companyId = $this->companySelector->getCompany();

        if (! $user instanceof User || ! $companyId instanceof Ulid || ! $user->getId() instanceof Ulid) {
            return null;
        }

        $key = $user->getId()->toBase32() . '|' . $companyId->toBase32();

        if (! array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = $this->repository->findOne($user, $companyId);
        }

        return $this->memberships[$key];
    }

    /**
     * Whether the open company is scheduled for closure — and so read-only.
     */
    public function isClosing(): bool
    {
        return $this->membership()?->getCompany()->isClosing() ?? false;
    }

    public function can(CompanyPermission $permission): bool
    {
        if ($this->isClosing() && ! in_array($permission, self::WHILE_CLOSING, true)) {
            return false;
        }

        return $this->role()?->can($permission) ?? false;
    }

    public function reset(): void
    {
        $this->memberships = [];
    }
}
