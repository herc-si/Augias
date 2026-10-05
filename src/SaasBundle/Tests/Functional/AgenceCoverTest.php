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

use Augias\CoreBundle\Company\ClosureReason;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\CompanyCoverage;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SaasBundle\Action\CoverCompanyAction;
use Augias\SaasBundle\Feature\Feature;
use Augias\SaasBundle\Retention\SubscriptionEndRetention;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureType;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\PlanFeature;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * An existing company put under its owner's Agence subscription, and the
 * warning a host gets before a plan that covers fewer companies.
 */
#[CoversClass(CoverCompanyAction::class)]
#[CoversClass(CoveredSubscriptionProvider::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class AgenceCoverTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private Plan $agence;

    private Plan $entreprise;

    private User $owner;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testAnExistingCompanyIsOfferedAndPutUnderTheAgency(): void
    {
        $this->plans();
        $this->owner = $this->user();
        $host = $this->company('Agency Co', $this->agence, SubscriptionStatus::ACTIVE);
        $client = $this->company('Client Co', $this->entreprise, SubscriptionStatus::PENDING);

        $browser = $this->browser()
            ->actingAs($this->owner)
            ->visit('/select-company/' . $client->getId())
            ->visit('/billing/')
            ->assertSuccessful()
            ->assertSee('The subscription of Agency Co can cover this company');

        $token = $browser->crawler()->filter('form[action="/billing/subscription/cover"] input[name=_token]')->attr('value');
        $browser
            ->post('/billing/subscription/cover', ['body' => ['_token' => $token]])
            ->assertSuccessful()
            ->assertSee('This company is covered by the subscription of Agency Co')
            ->assertNotSee('can cover this company');

        $coverage = $this->em->getRepository(CompanyCoverage::class)->findOneBy(['covered' => $client]);
        self::assertInstanceOf(CompanyCoverage::class, $coverage);
        self::assertTrue($coverage->getHost()->getId()->equals($host->getId()));
    }

    public function testACompanyThatPaysForItselfIsNotOffered(): void
    {
        $this->plans();
        $this->owner = $this->user();
        $this->company('Agency Co', $this->agence, SubscriptionStatus::ACTIVE);
        $paying = $this->company('Paying Co', $this->entreprise, SubscriptionStatus::ACTIVE, 'sub_paying');

        $this->browser()
            ->actingAs($this->owner)
            ->visit('/select-company/' . $paying->getId())
            ->visit('/billing/')
            ->assertSuccessful()
            ->assertNotSee('can cover this company');
    }

    public function testTheHostIsWarnedBeforeAPlanThatCoversFewer(): void
    {
        $this->plans();
        $this->owner = $this->user();
        $host = $this->company('Agency Co', $this->agence, SubscriptionStatus::ACTIVE);
        foreach (['First Client', 'Second Client'] as $day => $name) {
            $covered = $this->company($name, $this->entreprise, SubscriptionStatus::PENDING);
            $this->em->persist(new CompanyCoverage($covered, $host, new DateTimeImmutable('2026-10-0' . ($day + 1))));
        }
        $this->em->flush();

        $browser = $this->browser()
            ->actingAs($this->owner)
            ->visit('/select-company/' . $host->getId())
            ->visit('/billing/subscription/change')
            ->assertSuccessful();

        $token = $browser->crawler()->filter('input[name=plan][value="' . $this->entreprise->getPlanId() . '"]')->closest('form')?->filter('input[name=_token]')->attr('value');
        $browser
            ->post('/billing/subscription/change/confirm', ['body' => ['plan' => $this->entreprise->getPlanId(), '_token' => $token]])
            ->assertSuccessful()
            ->assertSee('These 2 companies will no longer be covered by the subscription')
            ->assertSee('First Client')
            ->assertSee('Second Client');
    }

    /**
     * Its own trial ended long ago; the agency pays for it now.
     */
    public function testACoveredCompanyIsNotDeletedForItsOwnEndedSubscription(): void
    {
        $this->plans();
        $this->owner = $this->user();
        $host = $this->company('Agency Co', $this->agence, SubscriptionStatus::ACTIVE);
        $covered = $this->company('Client Co', $this->entreprise, SubscriptionStatus::TRIAL, endDate: '2026-01-01');
        $this->em->persist(new CompanyCoverage($covered, $host, new DateTimeImmutable('2026-09-01')));
        $this->em->flush();

        // @phpstan-ignore symfonyContainer.serviceNotFound
        $retention = self::getContainer()->get(SubscriptionEndRetention::class);
        self::assertInstanceOf(SubscriptionEndRetention::class, $retention);
        $retention->reconcile();

        $this->em->refresh($covered);
        self::assertNotSame(ClosureReason::SubscriptionEnded, $covered->getClosureReason());
    }

    private function plans(): void
    {
        $this->agence = new Plan()->setName('Agence')->setPlanId('price_agence_month')->setPrice(3999);
        $this->entreprise = new Plan()->setName('Entreprise')->setPlanId('price_entreprise_month')->setPrice(1999);
        $this->em->persist($this->agence);
        $this->em->persist($this->entreprise);
        $this->em->persist(new PlanFeature()->setPlan($this->agence)->setFeatureKey(Feature::Companies->value)->setType(FeatureType::INTEGER)->setValue(5));
        $this->em->flush();

        // The rights are cached across tests by plan id: a new plan, a new id.
        // @phpstan-ignore symfonyContainer.serviceNotFound
        self::getContainer()->get(CoveredSubscriptionProvider::class)->reset();
    }

    private function user(): User
    {
        $user = UserFactory::createOne(['companies' => []]);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function company(string $name, Plan $plan, SubscriptionStatus $status, ?string $providerId = null, string $endDate = '+1 month'): Company
    {
        $company = CompanyFactory::createOne(['name' => $name]);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);
        $this->owner->addCompany($company, CompanyRole::Owner);

        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['subscriber' => $company]) ?? new Subscription()->setSubscriber($company);
        $subscription
            ->setPlan($plan)
            ->setStatus($status)
            ->setStartDate(new DateTimeImmutable('-1 month'))
            ->setEndDate(new DateTimeImmutable($endDate));
        if (null !== $providerId) {
            $subscription->setSubscriptionId($providerId);
        }
        $this->em->persist($subscription);
        $this->em->flush();

        return $company;
    }
}
