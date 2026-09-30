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

namespace Augias\SaasBundle\Tests\Payment\Stripe;

use Augias\CoreBundle\ConfigWriter;
use Augias\CoreBundle\Telemetry\Telemetry;
use Augias\CoreBundle\Tests\Telemetry\CollectingMessageBus;
use Augias\SaasBundle\Payment\RemotePlanSync;
use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\StripeSubscriptionSync;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Uid\Ulid;

#[CoversClass(StripeSubscriptionSync::class)]
final class StripeSubscriptionSyncTest extends TestCase
{
    private const int PERIOD_END = 1_800_000_000;

    private Plan $free;

    private Plan $solo;

    private Subscription $subscription;

    protected function setUp(): void
    {
        $this->free = new Plan()->setName('Free')->setPlanId('0')->setPrice(0);
        $this->solo = new Plan()->setName('Solo')->setPlanId('price_solo')->setPrice(1200);
        $this->subscription = new Subscription()
            ->setPlan($this->free)
            ->setStatus(SubscriptionStatus::TRIAL)
            ->setStartDate(new DateTimeImmutable('2026-09-20'))
            ->setEndDate(new DateTimeImmutable('2026-10-04'));
        new ReflectionProperty(Subscription::class, 'id')->setValue($this->subscription, new Ulid());
    }

    public function testAPaidSubscriptionIsActivatedOnItsPlanUntilThePeriodEnds(): void
    {
        $this->sync(['status' => 'active']);

        self::assertSame(SubscriptionStatus::ACTIVE, $this->subscription->getStatus());
        self::assertSame($this->solo, $this->subscription->getPlan());
        self::assertSame('sub_1', $this->subscription->getSubscriptionId());
        self::assertEquals(new DateTimeImmutable('@' . self::PERIOD_END), $this->subscription->getEndDate());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, SubscriptionStatus}>
     */
    public static function statuses(): iterable
    {
        yield 'trialing' => [['status' => 'trialing', 'trial_end' => self::PERIOD_END], SubscriptionStatus::TRIAL];
        yield 'cancelled at the period end' => [['status' => 'active', 'cancel_at_period_end' => true], SubscriptionStatus::CANCELLED];
        yield 'past due' => [['status' => 'past_due'], SubscriptionStatus::PAST_DUE];
        yield 'unpaid' => [['status' => 'unpaid'], SubscriptionStatus::UNPAID];
        yield 'paused' => [['status' => 'paused'], SubscriptionStatus::PAUSED];
        yield 'canceled' => [['status' => 'canceled'], SubscriptionStatus::EXPIRED];
    }

    /**
     * @param array<string, mixed> $remote
     */
    #[DataProvider('statuses')]
    public function testTheStripeStatusIsMirrored(array $remote, SubscriptionStatus $expected): void
    {
        $this->sync($remote);

        self::assertSame($expected, $this->subscription->getStatus());
    }

    public function testAFirstPaymentStillPendingChangesNothing(): void
    {
        $this->sync(['status' => 'incomplete']);

        self::assertSame(SubscriptionStatus::TRIAL, $this->subscription->getStatus());
        self::assertSame($this->free, $this->subscription->getPlan());
        self::assertNull($this->subscription->getSubscriptionId());
    }

    public function testTheEndOfAReplacedSubscriptionLeavesItsSuccessorAlone(): void
    {
        $this->subscription->setSubscriptionId('sub_new')->setStatus(SubscriptionStatus::ACTIVE);

        $this->sync(['status' => 'canceled']);

        self::assertSame(SubscriptionStatus::ACTIVE, $this->subscription->getStatus());
        self::assertSame('sub_new', $this->subscription->getSubscriptionId());
    }

    public function testAScheduledDowngradeAppliesWhenTheSubscriptionEnds(): void
    {
        $this->subscription->setPlan($this->solo)->setSubscriptionId('sub_1')->setPendingPlan($this->free);

        $this->sync(['status' => 'canceled']);

        self::assertSame($this->free, $this->subscription->getPlan());
        self::assertSame(SubscriptionStatus::ACTIVE, $this->subscription->getStatus());
        self::assertNull($this->subscription->getSubscriptionId());
    }

    public function testASubscriptionUnknownHereIsIgnored(): void
    {
        $sync = $this->makeSync(['status' => 'active', 'metadata' => []], found: false);

        self::assertNull($sync->sync('sub_1'));
    }

    private function telemetry(): Telemetry
    {
        return new Telemetry(
            new CollectingMessageBus(),
            new ConfigWriter($this->createStub(AbstractVault::class), '/tmp/augias-test-config'),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            'build-123',
            false,
            '',
            'manual',
            false,
            'fr',
            null,
        );
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function sync(array $remote): void
    {
        $this->makeSync($remote)->sync('sub_1');
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function makeSync(array $remote, bool $found = true): StripeSubscriptionSync
    {
        $remote += [
            'id' => 'sub_1',
            'current_period_end' => self::PERIOD_END,
            'metadata' => ['subscription_id' => $this->subscription->getId()->toBase58()],
            'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_solo']]]],
        ];

        $subscriptions = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptions->method('findOneBy')->willReturn($found ? $this->subscription : null);

        $plans = $this->createStub(PlanRepositoryInterface::class);
        $plans->method('find')->willReturnCallback(fn (string $id): ?Plan => ['price_solo' => $this->solo, '0' => $this->free][$id] ?? null);

        return new StripeSubscriptionSync(
            new StripeApi(new MockHttpClient([new JsonMockResponse($remote)], 'https://api.stripe.com/v1/')),
            $subscriptions,
            new SubscriptionManager($subscriptions, $plans, $this->createStub(PaymentIntegrationInterface::class)),
            new RemotePlanSync($subscriptions, $plans, new NullLogger(), $this->telemetry()),
            new NullLogger(),
        );
    }
}
