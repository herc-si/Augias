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
use Augias\SaasBundle\Plan\PlanPeriods;
use Augias\SaasBundle\Retention\SubscriptionEndRetention;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Entity\Trial;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * The yearly price made visible, and a subscription taken during the trial
 * shown and treated as one (05/10/2026: the trial label stayed after paying).
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class YearlyAndPaidTrialTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private Plan $decouverte;

    private Plan $solo;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testThePlansSayWhatAYearSaves(): void
    {
        $owner = $this->owner(SubscriptionStatus::PENDING);

        $this->browser()
            ->actingAs($owner)
            ->visit('/billing/subscription/plans')
            ->assertSuccessful()
            // Découverte: 30 a year for 3 a month, two months.
            ->assertSee('up to 2 months free')
            ->assertSee('or €100.00 a year, saving €19.88');
    }

    public function testAMonthlySubscriberIsInvitedToGoYearly(): void
    {
        $owner = $this->owner(SubscriptionStatus::ACTIVE, 'sub_monthly');

        $this->browser()
            ->actingAs($owner)
            ->visit('/billing/')
            ->assertSuccessful()
            ->assertSee('a year.')
            ->assertSee('Switch to yearly');
    }

    public function testASubscriptionTakenDuringTheTrialIsShownAsOne(): void
    {
        $owner = $this->owner(SubscriptionStatus::TRIAL, 'sub_trialing', '+20 days');

        $this->browser()
            ->actingAs($owner)
            ->visit('/billing/')
            ->assertSuccessful()
            ->assertSee('Subscribed')
            ->assertSee('First payment')
            ->assertNotSee('Trial ends');
    }

    /**
     * Its first payment is due: Stripe settles it and says so. Neither the
     * trial-ended page nor the purge step in meanwhile.
     */
    public function testATrialSubscribedFromIsNotOverAtItsDate(): void
    {
        $owner = $this->owner(SubscriptionStatus::TRIAL, 'sub_trialing', '-1 hour');

        $this->browser()
            ->actingAs($owner)
            ->visit('/dashboard')
            ->assertSuccessful()
            ->assertNotSee('Your trial has ended');

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $owner->getCompanies()->first()]);
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertFalse(SubscriptionEndRetention::hasEnded($subscription, new DateTimeImmutable()));
    }

    /**
     * "Your trial ends, your account locks": not for someone who subscribed.
     */
    public function testNoTrialEmailsOnceSubscribed(): void
    {
        $trialing = $this->owner(SubscriptionStatus::TRIAL, null, '+20 days');
        $subscribed = $this->owner(SubscriptionStatus::TRIAL, 'sub_trialing', '+20 days');

        foreach ([$trialing, $subscribed] as $user) {
            $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $user->getCompanies()->first()]);
            self::assertInstanceOf(Subscription::class, $subscription);
            $this->em->persist(new Trial()->setUser($user)->setSubscription($subscription));
        }
        $this->em->flush();

        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'augias:saas:dispatch-onboarding-emails']);

        self::assertStringContainsString('Processed 1 active trials', $tester->getDisplay());
    }

    private function plans(): void
    {
        $this->decouverte = new Plan()->setName('Découverte')->setPlanId('price_decouverte_month')->setPrice(300)->setDefault(true);
        $decouverteYear = new Plan()->setName('Découverte')->setPlanId('price_decouverte_year')->setPrice(3000);
        $this->solo = new Plan()->setName('Solo')->setPlanId('price_solo_month')->setPrice(999);
        $soloYear = new Plan()->setName('Solo')->setPlanId('price_solo_year')->setPrice(10000);
        foreach ([$this->decouverte, $decouverteYear, $this->solo, $soloYear] as $plan) {
            $this->em->persist($plan);
        }
        $this->em->flush();

        // @phpstan-ignore symfonyContainer.serviceNotFound
        $periods = self::getContainer()->get(PlanPeriods::class);
        self::assertInstanceOf(PlanPeriods::class, $periods);
        $periods->link($this->decouverte, $decouverteYear);
        $periods->link($this->solo, $soloYear);
    }

    private function owner(SubscriptionStatus $status, ?string $providerId = null, string $endDate = '+1 month'): User
    {
        if (! isset($this->solo)) {
            $this->plans();
        }

        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $owner = UserFactory::createOne(['companies' => []]);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $owner->addCompany($company, CompanyRole::Owner);

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $company]) ?? new Subscription()->setSubscriber($company);
        $subscription
            ->setPlan($this->solo)
            ->setStatus($status)
            ->setStartDate(new DateTimeImmutable('-10 days'))
            ->setEndDate(new DateTimeImmutable($endDate))
            ->setSubscriptionId($providerId);
        $this->em->persist($subscription);
        $this->em->flush();

        return $owner;
    }
}
