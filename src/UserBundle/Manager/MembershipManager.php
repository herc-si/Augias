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

namespace Augias\UserBundle\Manager;

use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Exception\MembershipRuleViolation;
use Doctrine\ORM\EntityManagerInterface;
use function in_array;

/**
 * Changes to who is in a company and in what role, and the rules they keep.
 *
 * A company has exactly one owner, always. It changes hands only when the
 * owner gives it: then the new owner takes the role and the former one stays
 * on as an administrator. Nobody else can take it, remove it, or demote it —
 * which is also why the owner cannot simply leave.
 *
 * Whether the person asking may manage members at all is settled before this
 * (see RoutePermissionMap); what is checked here is what they may do to whom.
 *
 * @see \Augias\UserBundle\Tests\Manager\MembershipManagerTest
 */
final readonly class MembershipManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function changeRole(Membership $actor, Membership $member, CompanyRole $role): void
    {
        $this->sameCompany($actor, $member);

        if (CompanyRole::Owner === $member->getRole()) {
            throw new MembershipRuleViolation('users.members.error.owner_role');
        }

        // The owner's role is given by handing the company over, never by a
        // change of role: that would leave two owners.
        if (CompanyRole::Owner === $role || ! in_array($role, $actor->getRole()->assignable(), true)) {
            throw new MembershipRuleViolation('users.members.error.role_not_assignable');
        }

        $member->setRole($role);
        $this->entityManager->flush();
    }

    public function remove(Membership $actor, Membership $member): void
    {
        $this->sameCompany($actor, $member);

        if (CompanyRole::Owner === $member->getRole()) {
            throw new MembershipRuleViolation('users.members.error.remove_owner');
        }

        if (! in_array($member->getRole(), $actor->getRole()->assignable(), true)) {
            throw new MembershipRuleViolation('users.members.error.role_not_assignable');
        }

        $this->drop($member);
    }

    public function leave(Membership $member): void
    {
        if (CompanyRole::Owner === $member->getRole()) {
            throw new MembershipRuleViolation('users.members.error.owner_leaves');
        }

        $this->drop($member);
    }

    public function transferOwnership(Membership $owner, Membership $member): void
    {
        $this->sameCompany($owner, $member);

        if (CompanyRole::Owner !== $owner->getRole()) {
            throw new MembershipRuleViolation('users.members.error.not_owner');
        }

        if ($owner === $member) {
            return;
        }

        $member->setRole(CompanyRole::Owner);
        $owner->setRole(CompanyRole::Admin);
        $this->entityManager->flush();
    }

    private function drop(Membership $member): void
    {
        $member->getUser()->removeCompany($member->getCompany());
        $this->entityManager->remove($member);
        $this->entityManager->flush();
    }

    private function sameCompany(Membership $actor, Membership $member): void
    {
        if (! $actor->getCompany()->getId()->equals($member->getCompany()->getId())) {
            throw new MembershipRuleViolation('users.members.error.not_a_member');
        }
    }
}
