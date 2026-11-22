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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Action\BillingPortalAction;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Ulid;

#[CoversClass(BillingPortalAction::class)]
final class BillingPortalActionTest extends TestCase
{
    private Session $session;

    public function testABilledSubscriptionOpensStripesPortal(): void
    {
        $subscription = new Subscription()->setSubscriptionId('sub_1');
        $payments = $this->createMock(PaymentIntegrationInterface::class);
        $payments->expects(self::once())->method('getCustomerPortalUrl')->with($subscription)->willReturn('https://billing.stripe.com/p/session/x');

        self::assertSame('https://billing.stripe.com/p/session/x', $this->action($subscription, $payments)()->getTargetUrl());
    }

    public function testWithoutAStripeSubscriptionThereIsNoCardToChange(): void
    {
        $payments = $this->createMock(PaymentIntegrationInterface::class);
        $payments->expects(self::never())->method('getCustomerPortalUrl');

        self::assertSame('/billing_index', $this->action(new Subscription(), $payments)()->getTargetUrl());
    }

    public function testAPortalThatCannotOpenSaysSoOnTheSubscriptionPage(): void
    {
        $payments = $this->createStub(PaymentIntegrationInterface::class);
        $payments->method('getCustomerPortalUrl')->willThrowException(new PaymentIntegrationException('No configuration provided'));

        self::assertSame('/billing_index', $this->action(new Subscription()->setSubscriptionId('sub_1'), $payments)()->getTargetUrl());
        self::assertSame(['saas.flash.portal_failed'], $this->session->getFlashBag()->get('error'));
    }

    private function action(Subscription $subscription, PaymentIntegrationInterface $payments): BillingPortalAction
    {
        $company = new Company();
        $companies = $this->createStub(CompanyRepository::class);
        $companies->method('find')->willReturn($company);

        $selector = new CompanySelector($this->createStub(ManagerRegistry::class));
        new ReflectionProperty(CompanySelector::class, 'companyId')->setValue($selector, new Ulid());

        $subscriptions = $this->createStub(SubscriptionProviderInterface::class);
        $subscriptions->method('getSubscriptionFor')->willReturn($subscription);

        $action = new BillingPortalAction($subscriptions, $payments, $companies, $selector, new NullLogger());

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $container = new Container();
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);
        $action->setContainer($container);

        return $action;
    }
}
