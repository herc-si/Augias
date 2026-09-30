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

namespace Augias\SaasBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Test instance, 30/09/2026: a second company, refused the free plan, stayed
 * in the company list, pending, with nothing to be done about it.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class AbandonPendingCompanyTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheOwnerTakesBackTheCompanyThatNeverStartedAndKeepsTheOther(): void
    {
        $free = new Plan()->setName('Free')->setPlanId('0')->setPrice(0);
        $this->em->persist($free);

        $owner = UserFactory::createOne(['companies' => []]);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);

        $kept = $this->company('Kept Shop', $owner, $free, SubscriptionStatus::ACTIVE);
        $pending = $this->company('Second Shop', $owner, $free, SubscriptionStatus::PENDING);
        $this->em->flush();

        $keptId = $kept->getId();
        $pendingId = $pending->getId();

        // As on a real request: nothing already in memory.
        $this->em->clear();

        $this->browser()
            ->actingAs($owner)
            ->visit('/select-company/' . $pendingId)
            ->visit('/billing/subscription/plans')
            // The owner's free company is found although the pending one is
            // selected: Free is not offered.
            ->assertSee('The free plan is limited to one company per account')
            ->assertSee('Abandon this company')
            ->click('Abandon this company')
            ->assertSee('The company "Second Shop" was deleted.');

        $this->em->clear();
        self::assertNull($this->em->find(Company::class, $pendingId));
        self::assertInstanceOf(Company::class, $this->em->find(Company::class, $keptId));
        self::assertSame([], $this->em->getRepository(Subscription::class)->findBy(['subscriber' => $pendingId]));
    }

    public function testACompanyThatRanIsNotOffered(): void
    {
        $free = new Plan()->setName('Free')->setPlanId('0')->setPrice(0);
        $this->em->persist($free);

        $owner = UserFactory::createOne(['companies' => []]);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);

        $only = $this->company('Only Shop', $owner, $free, SubscriptionStatus::ACTIVE);
        $this->em->flush();
        $onlyId = $only->getId();

        $this->browser()
            ->actingAs($owner)
            ->post('/billing/subscription/abandon', ['body' => ['_token' => 'wrong']])
            ->assertSee('Only Shop');

        $this->em->clear();
        self::assertInstanceOf(Company::class, $this->em->find(Company::class, $onlyId));
    }

    private function company(string $name, User $owner, Plan $plan, SubscriptionStatus $status): Company
    {
        $company = CompanyFactory::createOne(['name' => $name]);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);
        $owner->addCompany($company, CompanyRole::Owner);

        // Creating the company already gave it a subscription on the default
        // plan: that one is set, rather than a second added beside it.
        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $company]) ?? new Subscription()->setSubscriber($company);
        $subscription
            ->setPlan($plan)
            ->setStatus($status)
            ->setStartDate(new DateTimeImmutable('2026-09-30'))
            ->setEndDate(new DateTimeImmutable('2026-10-30'));
        $this->em->persist($subscription);

        return $company;
    }
}
