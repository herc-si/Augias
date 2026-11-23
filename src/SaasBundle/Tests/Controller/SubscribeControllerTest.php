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

namespace Augias\SaasBundle\Tests\Controller;

use Augias\CoreBundle\Company\CompanySelectorInterface;
use Augias\CoreBundle\ConfigWriter;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Telemetry\Telemetry;
use Augias\CoreBundle\Tests\Telemetry\CollectingMessageBus;
use Augias\ElectronicInvoicingBundle\Onboarding\OnboardingProgress;
use Augias\ElectronicInvoicingBundle\Onboarding\OnboardingProgressProviderInterface;
use Augias\SaasBundle\Action\ChoosePlanAction;
use Augias\SaasBundle\Controller\SubscribeController;
use Augias\UserBundle\Entity\User;
use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\Options;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[CoversClass(SubscribeController::class)]
final class SubscribeControllerTest extends TestCase
{
    private CollectingMessageBus $bus;

    protected function setUp(): void
    {
        $this->bus = new CollectingMessageBus();
    }

    public function testTransportExceptionRedirectsToOverviewWithErrorFlash(): void
    {
        $exception = new TransportException('HTTP/2 404 returned for "https://api.lemonsqueezy.com/v1/checkouts"');

        $response = $this->invokeController($exception);

        self::assertInstanceOf(RedirectResponse::class, $response[0]);
        self::assertSame('/billing/', $response[0]->getTargetUrl());
        self::assertArrayHasKey('error', $response[1]);
        self::assertNotEmpty($response[1]['error']);

        self::assertCount(1, $this->bus->messages);
        self::assertSame('saas_checkout_failed', $this->bus->messages[0]->payload['event']);
        self::assertSame('pro', $this->bus->messages[0]->payload['properties']['plan']);
    }

    public function testAStripeFailureRedirectsToOverviewWithErrorFlash(): void
    {
        // What the test instance hit with no Stripe key set: a 500 page.
        $exception = new PaymentIntegrationException('Stripe POST checkout/sessions failed (HTTP 401): You did not provide an API key.');

        $response = $this->invokeController($exception);

        self::assertInstanceOf(RedirectResponse::class, $response[0]);
        self::assertSame('/billing/', $response[0]->getTargetUrl());
        self::assertNotEmpty($response[1]['error'] ?? []);
        self::assertSame('saas_checkout_failed', $this->bus->messages[0]->payload['event']);
    }

    public function testHttpClientExceptionRedirectsToOverviewWithErrorFlash(): void
    {
        $exception = new class() extends RuntimeException implements ClientExceptionInterface {
            public function getResponse(): ResponseInterface
            {
                throw new LogicException('Not needed in test');
            }
        };

        $response = $this->invokeController($exception);

        self::assertInstanceOf(RedirectResponse::class, $response[0]);
        self::assertSame('/billing/', $response[0]->getTargetUrl());
        self::assertArrayHasKey('error', $response[1]);
        self::assertNotEmpty($response[1]['error']);

        self::assertCount(1, $this->bus->messages);
        self::assertSame('saas_checkout_failed', $this->bus->messages[0]->payload['event']);
    }

    /**
     * HERC SI invoices the subscriber: without a SIREN, the setup's first
     * step comes before the payment, and brings it back here.
     */
    public function testWithoutASirenTheCompanyIsAskedForItBeforePaying(): void
    {
        $response = $this->invokeController(null, hasSiren: false);

        self::assertSame('/electronic-invoicing/setup?next=/billing/checkout?from=pricing', $response[0]->getTargetUrl());
        self::assertSame([], $this->bus->messages);
    }

    public function testSuccessfulCheckoutEmitsCheckoutStartedTelemetryAndRedirects(): void
    {
        $response = $this->invokeController(null);

        self::assertInstanceOf(RedirectResponse::class, $response[0]);
        self::assertSame('https://checkout.lemonsqueezy.com/buy/abc', $response[0]->getTargetUrl());

        self::assertCount(1, $this->bus->messages);
        self::assertSame('event', $this->bus->messages[0]->type);
        self::assertSame('saas_checkout_started', $this->bus->messages[0]->payload['event']);
        self::assertSame('pro', $this->bus->messages[0]->payload['properties']['plan']);
    }

    public function testActiveTrialCheckoutGrantsLemonSqueezyTrial(): void
    {
        $subscription = $this->trialSubscription(
            SubscriptionStatus::TRIAL,
            CarbonImmutable::parse('2024-01-10'),
        );

        $options = $this->captureCheckoutOptions($subscription, CarbonImmutable::parse('2024-01-01'));

        self::assertFalse($options->getValue(Options::SKIP_TRIAL));
    }

    /**
     * The trial is the base plan's: a company in its trial that picks a
     * higher plan pays at once (19/11/2026).
     */
    public function testAHigherPlanChosenDuringTheTrialIsChargedNow(): void
    {
        $subscription = $this->trialSubscription(
            SubscriptionStatus::TRIAL,
            CarbonImmutable::parse('2024-01-10'),
        );

        $solo = new Plan();
        $solo->setName('Solo');
        $solo->setPlanId('higher-plan');
        $solo->setPrice(999);

        $options = $this->captureCheckoutOptions($subscription, CarbonImmutable::parse('2024-01-01'), $solo);

        self::assertTrue($options->getValue(Options::SKIP_TRIAL));
    }

    public function testExpiredTrialCheckoutSkipsTrial(): void
    {
        $subscription = $this->trialSubscription(
            SubscriptionStatus::TRIAL,
            CarbonImmutable::parse('2023-12-30'),
        );

        $options = $this->captureCheckoutOptions($subscription, CarbonImmutable::parse('2024-01-01'));

        self::assertTrue($options->getValue(Options::SKIP_TRIAL));
    }

    /**
     * @return iterable<string, array{SubscriptionStatus, string, string}>
     */
    public static function billedElsewhere(): iterable
    {
        yield 'paid' => [SubscriptionStatus::ACTIVE, '2024-01-31', 'saas.flash.checkout_already_subscribed'];
        yield 'on a trial with a card' => [SubscriptionStatus::TRIAL, '2024-01-10', 'saas.flash.checkout_already_subscribed'];
        yield 'paused' => [SubscriptionStatus::PAUSED, '2024-01-31', 'saas.flash.checkout_already_subscribed'];
        yield 'a payment late' => [SubscriptionStatus::PAST_DUE, '2024-01-31', 'saas.flash.checkout_settle_first'];
        yield 'unpaid' => [SubscriptionStatus::UNPAID, '2024-01-31', 'saas.flash.checkout_settle_first'];
        yield 'cancelled, still running' => [SubscriptionStatus::CANCELLED, '2024-01-31', 'saas.flash.checkout_still_running'];
    }

    /**
     * A second checkout would open a second subscription at Stripe, billed
     * alongside the first (Rémi, 23/11/2026).
     */
    #[DataProvider('billedElsewhere')]
    public function testASubscriptionBilledByStripeNeverOpensASecondCheckout(SubscriptionStatus $status, string $endsOn, string $reason): void
    {
        $subscription = $this->trialSubscription($status, CarbonImmutable::parse($endsOn), 'sub_existing');

        [$response, $flashes] = $this->refused($subscription, CarbonImmutable::parse('2024-01-01'));

        self::assertSame('/billing/', $response->getTargetUrl());
        self::assertSame([$reason], $flashes['warning'] ?? []);
        self::assertSame([], $this->bus->messages);
    }

    public function testAnEndedStripeSubscriptionMaySubscribeAgain(): void
    {
        $subscription = $this->trialSubscription(SubscriptionStatus::EXPIRED, CarbonImmutable::parse('2023-12-01'), 'sub_old');

        $options = $this->captureCheckoutOptions($subscription, CarbonImmutable::parse('2024-01-01'));

        self::assertTrue($options->getValue(Options::SKIP_TRIAL));
    }

    public function testCancelledCheckoutSkipsTrial(): void
    {
        $subscription = $this->trialSubscription(
            SubscriptionStatus::CANCELLED,
            CarbonImmutable::parse('2024-01-10'),
        );

        $options = $this->captureCheckoutOptions($subscription, CarbonImmutable::parse('2024-01-01'));

        self::assertTrue($options->getValue(Options::SKIP_TRIAL));
    }

    /**
     * @return array{0: RedirectResponse, 1: array<string, list<string|Stringable>>}
     */
    private function refused(Subscription $subscription, DateTimeImmutable $now): array
    {
        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->expects(self::never())->method('checkout');

        $subscriptionRepository = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->method('findOneBy')->willReturn($subscription);

        $companySelector = $this->createStub(CompanySelectorInterface::class);
        $companySelector->method('getCompany')->willReturn(new Ulid());

        $companyRepository = $this->createStub(CompanyRepository::class);
        $companyRepository->method('find')->willReturn(new Company());

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $controller = new SubscribeController(
            new SubscriptionManager($subscriptionRepository, $this->createStub(PlanRepositoryInterface::class), $paymentIntegration),
            $companyRepository,
            $companySelector,
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->makeTelemetry(),
            $clock,
            $this->companyWithSiren(),
        );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/billing/subscription/activate');
        $request->setSession($session);

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/billing/');

        $user = new User();
        $user->setEmail('test@example.com');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $container = new Container();
        $container->set('request_stack', new RequestStack([$request]));
        $container->set('router', $router);
        $container->set('security.token_storage', $tokenStorage);

        $controller->setContainer($container);

        /** @var RedirectResponse $response */
        $response = $controller(new Request());

        return [$response, $session->getFlashBag()->all()];
    }

    /**
     * @return array{0: RedirectResponse, 1: array<string, list<string|Stringable>>}
     */
    private function invokeController(?Throwable $exception, bool $hasSiren = true): array
    {
        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);

        if (! $hasSiren) {
            $paymentIntegration->expects(self::never())->method('checkout');
        } elseif ($exception instanceof Throwable) {
            $paymentIntegration->expects(self::once())->method('checkout')->willThrowException($exception);
        } else {
            $paymentIntegration->expects(self::once())->method('checkout')->willReturn('https://checkout.lemonsqueezy.com/buy/abc');
        }

        $plan = new Plan();
        $plan->setName('Pro');
        $plan->setPlanId('variant-123');
        $plan->setPrice(1000);

        $subscription = new Subscription();
        $subscription->setPlan($plan);

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->expects(self::once())->method('findOneBy')->willReturn($subscription);

        $subscriptionManager = new SubscriptionManager(
            $subscriptionRepository,
            $this->createStub(PlanRepositoryInterface::class),
            $paymentIntegration,
        );

        $companySelector = $this->createMock(CompanySelectorInterface::class);
        $companySelector->expects(self::once())->method('getCompany')->willReturn(new Ulid());

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->expects(self::once())->method('find')->willReturn(new Company());

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(CarbonImmutable::parse('2024-01-01'));

        $controller = new SubscribeController(
            $subscriptionManager,
            $companyRepository,
            $companySelector,
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->makeTelemetry(),
            $clock,
            $this->companyWithSiren($hasSiren),
        );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/billing/subscription/activate');
        $request->setSession($session);

        $requestStack = new RequestStack([$request]);

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters = []): string => '_einvoicing_setup' === $name ? '/electronic-invoicing/setup?next=' . $parameters['next'] : '/billing/');

        $user = new User();
        $user->setEmail('test@example.com');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::once())->method('getToken')->willReturn($token);

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('router', $router);
        $container->set('security.token_storage', $tokenStorage);

        $controller->setContainer($container);

        /** @var RedirectResponse $response */
        $response = $controller(Request::create('/billing/checkout?from=pricing'));

        return [$response, $session->getFlashBag()->all()];
    }

    /**
     * A company HERC SI can invoice: the checkout is not sent to the setup first.
     */
    private function companyWithSiren(bool $hasSiren = true): OnboardingProgressProviderInterface
    {
        $provider = $this->createStub(OnboardingProgressProviderInterface::class);
        $provider->method('progress')->willReturn(new OnboardingProgress(identityDone: $hasSiren, hasSiren: $hasSiren, platform: null, connected: false, verification: null, canConnectHere: false));

        return $provider;
    }

    private function makeTelemetry(): Telemetry
    {
        $vault = $this->createStub(AbstractVault::class);
        $vault->method('generateKeys')->willReturn(true);

        return new Telemetry(
            $this->bus,
            new ConfigWriter($vault, '/tmp/augias-test-config'),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            'build-123',
            true,
            'https://collector.example.com',
            'manual',
            false,
            'en',
            null,
        );
    }

    private function trialSubscription(
        SubscriptionStatus $status,
        DateTimeImmutable $endDate,
        ?string $subscriptionId = null,
    ): Subscription {
        // The base plan: the one with a trial.
        $plan = new Plan();
        $plan->setName('Découverte');
        $plan->setPlanId('variant-123');
        $plan->setPrice(300);
        $plan->setTrialDuration(new DateInterval('P30D'));

        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->setStatus($status);
        $subscription->setStartDate(CarbonImmutable::parse('2024-01-01'));
        $subscription->setEndDate($endDate);
        $subscription->setSubscriptionId($subscriptionId);

        return $subscription;
    }

    private function captureCheckoutOptions(Subscription $subscription, DateTimeImmutable $now, ?Plan $chosen = null): Options
    {
        $capturedOptions = null;

        $paymentIntegration = $this->createMock(PaymentIntegrationInterface::class);
        $paymentIntegration->expects(self::once())->method('checkout')->willReturnCallback(
            static function (Subscription $s, ?Options $options) use (&$capturedOptions): string {
                $capturedOptions = $options;

                return 'https://checkout.lemonsqueezy.com/buy/abc';
            }
        );

        $subscriptionRepository = $this->createMock(SubscriptionRepositoryInterface::class);
        $subscriptionRepository->expects(self::once())->method('findOneBy')->willReturn($subscription);

        $subscriptionManager = new SubscriptionManager(
            $subscriptionRepository,
            $this->createStub(PlanRepositoryInterface::class),
            $paymentIntegration,
        );

        $companySelector = $this->createMock(CompanySelectorInterface::class);
        $companySelector->expects(self::once())->method('getCompany')->willReturn(new Ulid());

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->expects(self::once())->method('find')->willReturn(new Company());

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $planRepository = $this->createStub(PlanRepositoryInterface::class);
        $planRepository->method('find')->willReturn($chosen);

        $controller = new SubscribeController(
            $subscriptionManager,
            $companyRepository,
            $companySelector,
            $planRepository,
            $this->createStub(EntityManagerInterface::class),
            $this->makeTelemetry(),
            $clock,
            $this->companyWithSiren(),
        );

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/billing/subscription/activate');
        $request->setSession($session);

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/billing/');

        $user = new User();
        $user->setEmail('test@example.com');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::once())->method('getToken')->willReturn($token);

        $container = new Container();
        $container->set('request_stack', new RequestStack([$request]));
        $container->set('router', $router);
        $container->set('security.token_storage', $tokenStorage);

        $controller->setContainer($container);
        $controller($chosen instanceof Plan ? new Request([ChoosePlanAction::PENDING_PLAN_QUERY_PARAMETER => 'higher-plan']) : new Request());

        self::assertInstanceOf(Options::class, $capturedOptions);

        return $capturedOptions;
    }
}
