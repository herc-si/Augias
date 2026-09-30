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

namespace Augias\SaasBundle\Tests\Plan;

use Augias\CoreBundle\Entity\Company;
use Augias\SaasBundle\Plan\FreePlanAllowance;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SolidWorx\Platform\PlatformBundle\Feature\SubscribableInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use SplObjectStorage;
use Symfony\Component\Uid\Ulid;

#[CoversClass(FreePlanAllowance::class)]
final class FreePlanAllowanceTest extends TestCase
{
    use BuildsFreePlanAllowance;

    private Plan $free;

    private Plan $solo;

    /**
     * @var SplObjectStorage<Company, Subscription>
     */
    private SplObjectStorage $subscriptions;

    protected function setUp(): void
    {
        $this->free = new Plan()->setName('Free')->setPlanId('0')->setPrice(0);
        $this->solo = new Plan()->setName('Solo')->setPlanId('price_solo')->setPrice(900);
        $this->subscriptions = new SplObjectStorage();
    }

    public function testTheFirstCompanyMayBeFree(): void
    {
        $owner = new User();
        $company = $this->company($owner);

        self::assertTrue($this->allowance()->allows($company));
    }

    /**
     * Test instance, 30/09/2026: an account on Free could open a second
     * company, itself on Free, and so on.
     */
    public function testASecondCompanyOfTheSameOwnerMayNotBeFree(): void
    {
        $owner = new User();
        $this->subscribe($this->company($owner), $this->free, SubscriptionStatus::ACTIVE);
        $second = $this->company($owner);

        self::assertFalse($this->allowance()->allows($second));
        self::assertSame([$this->solo], $this->allowance()->plansFor($second, [$this->free, $this->solo]));
    }

    public function testTheFreeCompanyItselfStaysAllowed(): void
    {
        $owner = new User();
        $company = $this->company($owner);
        $this->subscribe($company, $this->free, SubscriptionStatus::ACTIVE);

        self::assertTrue($this->allowance()->allows($company));
    }

    public function testAPaidOrUnfinishedCompanyDoesNotUseUpTheFreeOne(): void
    {
        $owner = new User();
        $this->subscribe($this->company($owner), $this->solo, SubscriptionStatus::ACTIVE);
        $this->subscribe($this->company($owner), $this->free, SubscriptionStatus::PENDING);

        self::assertTrue($this->allowance()->allows($this->company($owner)));
    }

    /**
     * Being invited into someone else's free company is not owning one.
     */
    public function testAFreeCompanyTheUserWasOnlyInvitedIntoDoesNotCount(): void
    {
        $user = new User();
        $theirs = $this->company(new User());
        $theirs->addUser($user, CompanyRole::Admin);
        $this->subscribe($theirs, $this->free, SubscriptionStatus::ACTIVE);

        self::assertTrue($this->allowance()->allows($this->company($user)));
        self::assertFalse($this->allowance()->ownsFreeCompany($user));
    }

    private function allowance(): FreePlanAllowance
    {
        $provider = $this->createStub(SubscriptionProviderInterface::class);
        $provider->method('getSubscriptionFor')->willReturnCallback(
            fn (SubscribableInterface $company): ?Subscription => $company instanceof Company && $this->subscriptions->offsetExists($company)
                ? $this->subscriptions[$company]
                : null,
        );

        return $this->freePlanAllowance($provider);
    }

    private function company(User $owner): Company
    {
        $company = new Company();
        new ReflectionProperty(Company::class, 'id')->setValue($company, new Ulid());
        $company->addUser($owner, CompanyRole::Owner);

        return $company;
    }

    private function subscribe(Company $company, Plan $plan, SubscriptionStatus $status): void
    {
        $subscription = new Subscription();
        $subscription->setSubscriber($company);
        $subscription->setPlan($plan);
        $subscription->setStatus($status);

        $this->subscriptions[$company] = $subscription;
    }
}
