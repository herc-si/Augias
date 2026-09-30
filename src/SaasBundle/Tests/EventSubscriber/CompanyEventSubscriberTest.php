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
use Augias\CoreBundle\Event\CompanyCreatedEvent;
use Augias\SaasBundle\EventSubscriber\CompanyEventSubscriber;
use Augias\SaasBundle\Plan\DefaultPlanProvider;
use Augias\UserBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

#[CoversClass(CompanyEventSubscriber::class)]
final class CompanyEventSubscriberTest extends TestCase
{
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
     * @param list<Subscription> $saved
     */
    private function subscriber(Plan $default, array &$saved): CompanyEventSubscriber
    {
        $plans = $this->createStub(PlanRepositoryInterface::class);
        $plans->method('findDefault')->willReturn($default);
        $plans->method('find')->willReturn($default);

        $subscriptions = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptions->method('save')->willReturnCallback(static function (object $subscription) use (&$saved): void {
            $saved[] = $subscription;
        });

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $trials = $this->createStub(TrialManagerInterface::class);
        $trials->method('userHasTrial')->willReturn(false);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/billing/subscription/plans');

        return new CompanyEventSubscriber(
            new DefaultPlanProvider($plans),
            new SubscriptionManager($subscriptions, $plans, $this->createStub(PaymentIntegrationInterface::class)),
            $security,
            $trials,
            $this->createStub(EntityManagerInterface::class),
            $router,
        );
    }
}
