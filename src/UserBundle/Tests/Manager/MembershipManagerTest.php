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

namespace Augias\UserBundle\Tests\Manager;

use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Exception\MembershipRuleViolation;
use Augias\UserBundle\Manager\MembershipManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A company always has exactly one owner, and only the owner gives it away.
 */
#[CoversClass(MembershipManager::class)]
final class MembershipManagerTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
    }

    public function testAnAdministratorChangesABillingMemberIntoAnAccountant(): void
    {
        $admin = $this->member(CompanyRole::Admin);
        $billing = $this->member(CompanyRole::Billing);

        $this->manager()->changeRole($admin, $billing, CompanyRole::Accountant);

        self::assertSame(CompanyRole::Accountant, $billing->getRole());
    }

    public function testNobodyIsMadeOwnerByAChangeOfRole(): void
    {
        $this->expectExceptionObject(new MembershipRuleViolation('users.members.error.role_not_assignable'));

        $this->manager()->changeRole($this->member(CompanyRole::Owner), $this->member(CompanyRole::Admin), CompanyRole::Owner);
    }

    public function testTheOwnersRoleDoesNotChange(): void
    {
        $this->expectExceptionObject(new MembershipRuleViolation('users.members.error.owner_role'));

        $this->manager()->changeRole($this->member(CompanyRole::Admin), $this->member(CompanyRole::Owner), CompanyRole::Billing);
    }

    public function testBillingManagesNobody(): void
    {
        $this->expectException(MembershipRuleViolation::class);

        $this->manager()->remove($this->member(CompanyRole::Billing), $this->member(CompanyRole::Accountant));
    }

    public function testTheOwnerIsNeverRemoved(): void
    {
        $this->expectExceptionObject(new MembershipRuleViolation('users.members.error.remove_owner'));

        $this->manager()->remove($this->member(CompanyRole::Admin), $this->member(CompanyRole::Owner));
    }

    public function testTheOwnerDoesNotLeave(): void
    {
        $this->expectExceptionObject(new MembershipRuleViolation('users.members.error.owner_leaves'));

        $this->manager()->leave($this->member(CompanyRole::Owner));
    }

    public function testHandingOverKeepsOneOwner(): void
    {
        $owner = $this->member(CompanyRole::Owner);
        $admin = $this->member(CompanyRole::Admin);

        $this->manager()->transferOwnership($owner, $admin);

        self::assertSame(CompanyRole::Owner, $admin->getRole());
        self::assertSame(CompanyRole::Admin, $owner->getRole());
    }

    public function testOnlyTheOwnerHandsOver(): void
    {
        $this->expectExceptionObject(new MembershipRuleViolation('users.members.error.not_owner'));

        $this->manager()->transferOwnership($this->member(CompanyRole::Admin), $this->member(CompanyRole::Billing));
    }

    public function testNothingIsDoneAcrossCompanies(): void
    {
        $this->expectExceptionObject(new MembershipRuleViolation('users.members.error.not_a_member'));

        $other = new Membership(new User(), new Company(), CompanyRole::Billing);

        $this->manager()->remove($this->member(CompanyRole::Owner), $other);
    }

    private function member(CompanyRole $role): Membership
    {
        return new Membership(new User(), $this->company, $role);
    }

    private function manager(): MembershipManager
    {
        return new MembershipManager($this->createStub(EntityManagerInterface::class));
    }
}
