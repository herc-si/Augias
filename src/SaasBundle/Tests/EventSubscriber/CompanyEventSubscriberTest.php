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

namespace Augias\SaasBundle\Tests\EventSubscriber;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\CompanyCoverage;
use Augias\CoreBundle\Event\CompanyCreatedEvent;
use Augias\SaasBundle\EventSubscriber\CompanyEventSubscriber;
use Augias\SaasBundle\Plan\DefaultPlanProvider;
use Augias\SaasBundle\Tests\Plan\BuildsFreePlanAllowance;
use Augias\SaasBundle\Tests\Subscription\BuildsCoveredSubscriptionProvider;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Trial\TrialManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;

#[CoversClass(CompanyEventSubscriber::class)]
final class CompanyEventSubscriberTest extends TestCase
{
    use BuildsFreePlanAllowance;
    use BuildsCoveredSubscriptionProvider;

    /**
     * Sign-up on the test instance (29/09/2026): Free as the default plan, no
     * trial, so the new company was sent to the plan page, which checked it
     * out — a payment session for a free plan, refused by the payment
     * provider, shown right under the welcome message.
     */
    public function testACompanyOnTheFreeDefaultIsActiveAtOnceAndGoesNowhereElse(): void
    {
        $free = new Plan()->setName('Free')->setPlanId('0')->setPrice(0);
        $saved = [];

        $subscriber = $this->subscriber($free, $saved);
        $subscriber->onCompanyCreated(new CompanyCreatedEvent(new Company()));

        $response = new Response('dashboard');
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onResponse($event);

        self::assertSame($response, $event->getResponse());
        $subscription = end($saved);
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
    }

    /**
     * Test instance, 30/09/2026: an account already on Free created another
     * company, and it was on Free too. One free company per account: the
     * second one stays pending and is sent to choose a paid plan.
     */
    public function testASecondFreeCompanyOfTheSameOwnerGoesToThePlanPageInstead(): void
    {
        $free = new Plan()->setName('Free')->setPlanId('0')->setPrice(0);
        $saved = [];

        $owner = new User();
        $first = $this->company($owner);
        $firstSubscription = new Subscription();
        $firstSubscription->setSubscriber($first);
        $firstSubscription->setPlan($free);
        $firstSubscription->setStatus(SubscriptionStatus::ACTIVE);

        $subscriber = $this->subscriber($free, $saved, [$firstSubscription]);
        $subscriber->onCompanyCreated(new CompanyCreatedEvent($this->company($owner)));

        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new Response());
        $subscriber->onResponse($event);

        self::assertSame('/billing/subscription/plans', $event->getResponse()->headers->get('Location'));
        $subscription = end($saved);
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertNotSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
    }

    public function testAPaidDefaultWithoutTrialStillGoesToThePlanPage(): void
    {
        $solo = new Plan()->setName('Solo')->setPlanId('price_solo')->setPrice(900);
        $saved = [];

        $subscriber = $this->subscriber($solo, $saved);
        $subscriber->onCompanyCreated(new CompanyCreatedEvent(new Company()));

        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new Response());
        $subscriber->onResponse($event);

        self::assertSame('/billing/subscription/plans', $event->getResponse()->headers->get('Location'));
    }

    /**
     * An owner whose agency plan has room: the new company goes under it,
     * with no trial and no detour through the plans.
     */
    public function testACompanyOpenedByAnAgencysOwnerIsCoveredByIt(): void
    {
        $decouverte = new Plan()->setName('Découverte')->setPlanId('price_decouverte')->setPrice(300)->setTrialDuration(new DateInterval('P30D'));
        $agence = new Plan()->setName('Agence')->setPlanId('price_agence')->setPrice(3999);
        $saved = [];

        $owner = new User();
        $host = $this->company($owner);
        $hostSubscription = new Subscription()->setSubscriber($host)->setPlan($agence)->setStatus(SubscriptionStatus::ACTIVE);

        $subscriber = $this->subscriber($decouverte, $saved, [$hostSubscription], $owner, ['price_agence' => 5], $covers);
        $company = $this->company($owner);
        $subscriber->onCompanyCreated(new CompanyCreatedEvent($company));

        $response = new Response('dashboard');
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onResponse($event);

        self::assertSame($response, $event->getResponse());
        self::assertCount(1, $covers);
        self::assertSame($company, $covers[0]->getCovered());
        self::assertSame($host, $covers[0]->getHost());
        $own = end($saved);
        self::assertInstanceOf(Subscription::class, $own);
        self::assertSame(SubscriptionStatus::PENDING, $own->getStatus(), 'Kept to fall back on, never started.');
    }

    private function company(User $owner): Company
    {
        $company = new Company();
        new ReflectionProperty(Company::class, 'id')->setValue($company, new Ulid());
        $company->addUser($owner, CompanyRole::Owner);

        return $company;
    }

    /**
     * @param list<Subscription> $saved
     * @param list<Subscription>         $existing
     * @param array<string, int>         $allowances
     * @param list<CompanyCoverage>|null $covers
     */
    private function subscriber(Plan $default, array &$saved, array $existing = [], ?User $user = null, array $allowances = [], ?array &$covers = null): CompanyEventSubscriber
    {
        $plans = $this->createStub(PlanRepositoryInterface::class);
        $plans->method('findDefault')->willReturn($default);
        $plans->method('find')->willReturn($default);

        $subscriptions = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptions->method('findOneBy')->willReturnCallback(static function (array $criteria) use ($existing): ?Subscription {
            foreach ($existing as $subscription) {
                if ($subscription->getSubscriber() === $criteria['subscriber']) {
                    return $subscription;
                }
            }

            return null;
        });
        $subscriptions->method('save')->willReturnCallback(static function (object $subscription) use (&$saved): void {
            $saved[] = $subscription;
        });

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user ?? new User());

        $trials = $this->createStub(TrialManagerInterface::class);
        $trials->method('userHasTrial')->willReturn(false);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/billing/subscription/plans');

        $manager = new SubscriptionManager($subscriptions, $plans, $this->createStub(PaymentIntegrationInterface::class));

        return new CompanyEventSubscriber(
            new DefaultPlanProvider($plans),
            $manager,
            $security,
            $trials,
            $this->createStub(EntityManagerInterface::class),
            $router,
            $this->freePlanAllowance($manager),
            $this->coveredSubscriptionProvider($manager, [], $allowances, $user, $covers),
        );
    }
}
