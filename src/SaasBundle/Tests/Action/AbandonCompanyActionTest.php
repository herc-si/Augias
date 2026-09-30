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

namespace Augias\SaasBundle\Tests\Action;

use Augias\CoreBundle\Entity\Company;
use Augias\SaasBundle\Action\AbandonCompanyAction;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AbandonCompanyAction::class)]
final class AbandonCompanyActionTest extends TestCase
{
    /**
     * Test instance, 30/09/2026: a second company, refused the free plan and
     * unable to pay, stayed in the company list, pending, for good.
     */
    public function testItsOwnerMayTakeBackACompanyThatNeverStarted(): void
    {
        $owner = new User();
        $company = $this->company($owner, CompanyRole::Owner);

        self::assertTrue(AbandonCompanyAction::canBeAbandoned($company, $owner, $this->subscription(SubscriptionStatus::PENDING)));
    }

    /**
     * Anything that ran has data and history: it closes by the ordinary
     * closure, with its notice period, not from the plan page.
     *
     * @return iterable<string, array{SubscriptionStatus}>
     */
    public static function startedStatuses(): iterable
    {
        yield 'active' => [SubscriptionStatus::ACTIVE];
        yield 'trial' => [SubscriptionStatus::TRIAL];
        yield 'cancelled' => [SubscriptionStatus::CANCELLED];
        yield 'expired' => [SubscriptionStatus::EXPIRED];
    }

    #[DataProvider('startedStatuses')]
    public function testACompanyThatRanCannotBeAbandoned(SubscriptionStatus $status): void
    {
        $owner = new User();
        $company = $this->company($owner, CompanyRole::Owner);

        self::assertFalse(AbandonCompanyAction::canBeAbandoned($company, $owner, $this->subscription($status)));
    }

    public function testOnlyTheOwnerMayAbandonIt(): void
    {
        $admin = new User();
        $company = $this->company($admin, CompanyRole::Admin);

        self::assertFalse(AbandonCompanyAction::canBeAbandoned($company, $admin, $this->subscription(SubscriptionStatus::PENDING)));
        self::assertFalse(AbandonCompanyAction::canBeAbandoned($company, new User(), $this->subscription(SubscriptionStatus::PENDING)));
    }

    public function testACompanyWithoutSubscriptionIsLeftAlone(): void
    {
        $owner = new User();

        self::assertFalse(AbandonCompanyAction::canBeAbandoned($this->company($owner, CompanyRole::Owner), $owner, null));
    }

    private function company(User $user, CompanyRole $role): Company
    {
        $company = new Company();
        new ReflectionProperty(Company::class, 'id')->setValue($company, new Ulid());
        $company->addUser($user, $role);

        return $company;
    }

    private function subscription(SubscriptionStatus $status): Subscription
    {
        $subscription = new Subscription();
        $subscription->setPlan(new Plan()->setName('Free')->setPlanId('0')->setPrice(0));
        $subscription->setStatus($status);

        return $subscription;
    }
}
