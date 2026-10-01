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
 * One offer, two periods: Solo at 9 € a month or 100 € a year. The customer
 * sees one Solo card with a monthly/yearly switch, in the currency the service
 * sells in, and a change between periods is weighed as one.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class AnnualPlansTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private Plan $soloMonthly;

    private Plan $soloYearly;

    private Plan $business;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testThePricingPageShowsOneOfferWithTwoPeriodsInEuros(): void
    {
        $owner = $this->ownerOnPending();

        $browser = $this->browser()
            ->actingAs($owner)
            ->visit('/billing/subscription/plans')
            ->assertSuccessful()
            ->assertSee('Monthly')
            ->assertSee('Yearly')
            ->assertSee('EUR')
            ->assertNotSee('USD');

        // One Solo card, not two.
        self::assertCount(1, $browser->crawler()->filter('.plan-card h2')->reduce(static fn ($node): bool => 'Solo' === trim($node->text())));
        self::assertSame($this->soloMonthly->getPlanId(), $this->choiceFor($browser, 'Solo'));

        $browser
            ->visit('/billing/subscription/plans?period=year')
            ->assertSuccessful()
            ->assertSee('/year')
            ->assertSee('8.33')
            ->assertSee('save');

        self::assertSame($this->soloYearly->getPlanId(), $this->choiceFor($browser, 'Solo'));
        // An offer with no yearly price keeps showing its monthly one.
        self::assertSame($this->business->getPlanId(), $this->choiceFor($browser, 'Business'));
    }

    public function testAChangeBetweenPeriodsIsWeighedAsOne(): void
    {
        $this->plans();
        $periods = self::getContainer()->get(PlanPeriods::class);

        self::assertTrue($periods->isAnnual($this->soloYearly));
        self::assertFalse($periods->isAnnual($this->soloMonthly));
        self::assertSame(833, $periods->monthlyEquivalent($this->soloYearly));
        self::assertSame(800, $periods->yearlySaving($this->soloMonthly, $this->soloYearly));

        self::assertFalse($periods->isDowngrade($this->soloMonthly, $this->soloYearly), 'Going yearly pays a year now: a step up.');
        self::assertTrue($periods->isDowngrade($this->soloYearly, $this->soloMonthly), 'Going monthly waits for the end of the year paid for.');
        self::assertFalse($periods->isDowngrade($this->soloYearly, $this->business), 'Business costs more each month than a year of Solo.');
        self::assertTrue($periods->isDowngrade($this->business, $this->soloYearly));
        self::assertSame([$this->soloMonthly, $this->business], $periods->offers([$this->soloMonthly, $this->soloYearly, $this->business]));
    }

    public function testTheChangePageOpensOnThePeriodTheCompanyIsBilledOn(): void
    {
        $owner = $this->ownerOnPending();
        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $owner->getCompanies()->first()]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->setPlan($this->em->find(Plan::class, $this->soloYearly->getId()))->setStatus(SubscriptionStatus::ACTIVE);
        $this->em->flush();

        $browser = $this->browser()
            ->actingAs($owner)
            ->visit('/billing/subscription/change')
            ->assertSuccessful()
            ->assertSee('/year');

        self::assertCount(1, $browser->crawler()->filter('.plan-card-current'));
    }

    private function choiceFor(\Zenstruck\Browser\KernelBrowser $browser, string $offer): string
    {
        $card = $browser->crawler()->filter('.plan-card')->reduce(static fn ($node): bool => $offer === trim($node->filter('h2')->text()));

        return (string) $card->filter('input[name=plan]')->attr('value');
    }

    private function plans(): void
    {
        $free = new Plan()->setName('Free')->setPlanId('0')->setPrice(0);
        $this->soloMonthly = new Plan()->setName('Solo')->setPlanId('price_solo_month')->setPrice(900);
        $this->soloYearly = new Plan()->setName('Solo')->setPlanId('price_solo_year')->setPrice(10000);
        $this->business = new Plan()->setName('Business')->setPlanId('price_business_month')->setPrice(1900);

        foreach ([$free, $this->soloMonthly, $this->soloYearly, $this->business] as $plan) {
            $this->em->persist($plan);
        }

        $this->em->flush();
        self::getContainer()->get(PlanPeriods::class)->link($this->soloMonthly, $this->soloYearly);
    }

    private function ownerOnPending(): User
    {
        $this->plans();

        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $owner = UserFactory::createOne(['companies' => []]);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $owner->addCompany($company, CompanyRole::Owner);

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $company]) ?? new Subscription()->setSubscriber($company);
        $subscription
            ->setPlan($this->em->find(Plan::class, $this->soloMonthly->getId()))
            ->setStatus(SubscriptionStatus::PENDING)
            ->setStartDate(new DateTimeImmutable('-1 day'))
            ->setEndDate(new DateTimeImmutable('+1 month'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $owner;
    }
}
