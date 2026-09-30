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

use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\StripeIntegration;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\Options;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;
use function array_shift;
use function parse_str;

#[CoversClass(StripeIntegration::class)]
#[CoversClass(StripeApi::class)]
final class StripeIntegrationTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $requests = [];

    public function testCheckoutSellsThePlanPriceWithTheLocalSubscriptionInMetadata(): void
    {
        $subscription = $this->subscription('price_solo', '+10 days');

        $url = $this->integration([new JsonMockResponse(['url' => 'https://checkout.stripe.com/c/pay/cs_1'])])
            ->checkout($subscription, Options::new()->withEmail('jo@acme.fr')->withSkipTrial(true));

        self::assertSame('https://checkout.stripe.com/c/pay/cs_1', $url);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringEndsWith('/v1/checkout/sessions', $this->requests[0]['url']);

        $body = $this->requests[0]['body'];
        self::assertSame('subscription', $body['mode']);
        self::assertSame([['price' => 'price_solo', 'quantity' => '1']], $body['line_items']);
        self::assertSame(['card', 'sepa_debit'], $body['payment_method_types']);
        self::assertSame('jo@acme.fr', $body['customer_email']);
        self::assertSame($subscription->getId()->toBase58(), $body['client_reference_id']);
        self::assertSame(['metadata' => ['subscription_id' => $subscription->getId()->toBase58()]], $body['subscription_data']);
        self::assertSame('https://app.test/billing_index', $body['cancel_url']);
        self::assertSame('https://app.test/saas_payment_success', $body['success_url']);
    }

    public function testTheRemainingTrialCarriesOverToStripe(): void
    {
        $subscription = $this->subscription('price_solo', '+10 days');

        $this->integration([new JsonMockResponse(['url' => 'https://checkout.stripe.com/x'])])
            ->checkout($subscription, Options::new()->withSkipTrial(false));

        self::assertSame((string) $subscription->getEndDate()->getTimestamp(), $this->requests[0]['body']['subscription_data']['trial_end']);
    }

    public function testATrialEndingWithinTwoDaysIsNotCarriedOver(): void
    {
        $subscription = $this->subscription('price_solo', '+36 hours');

        $this->integration([new JsonMockResponse(['url' => 'https://checkout.stripe.com/x'])])
            ->checkout($subscription, Options::new()->withSkipTrial(false));

        self::assertArrayNotHasKey('trial_end', $this->requests[0]['body']['subscription_data']);
    }

    public function testPlansAreTheActiveRecurringPrices(): void
    {
        $plans = [...$this->integration([new JsonMockResponse(['data' => [
            ['id' => 'price_solo', 'unit_amount' => 1200, 'recurring' => ['interval' => 'month', 'interval_count' => 1], 'product' => ['active' => true, 'name' => 'Solo', 'description' => 'Pour un indépendant']],
            ['id' => 'price_old', 'unit_amount' => 900, 'recurring' => ['interval' => 'month', 'interval_count' => 1], 'product' => ['active' => false, 'name' => 'Ancien', 'description' => null]],
        ]])])->getPlans()];

        self::assertCount(1, $plans);
        self::assertSame('price_solo', $plans[0]->id);
        self::assertSame('Solo', $plans[0]->name);
        self::assertSame(1200, $plans[0]->price);
        self::assertSame(1, $plans[0]->interval->m);
    }

    public function testChangingThePlanSwapsThePriceOfTheSubscriptionItem(): void
    {
        $subscription = $this->subscription('price_solo', '+10 days')->setSubscriptionId('sub_1');
        $newPlan = new Plan()->setName('Business')->setPlanId('price_business')->setPrice(2900);

        $renew = $this->integration([
            new JsonMockResponse(['id' => 'sub_1', 'items' => ['data' => [['id' => 'si_1']]]]),
            new JsonMockResponse(['id' => 'sub_1', 'current_period_end' => 1_800_000_000]),
        ])->changePlan($subscription, $newPlan);

        self::assertEquals(new DateTimeImmutable('@1800000000'), $renew);
        self::assertStringEndsWith('/v1/subscriptions/sub_1', $this->requests[1]['url']);
        self::assertSame([['id' => 'si_1', 'price' => 'price_business']], $this->requests[1]['body']['items']);
        self::assertSame('always_invoice', $this->requests[1]['body']['proration_behavior']);
    }

    public function testCancellingAndResumingToggleCancelAtPeriodEnd(): void
    {
        $subscription = $this->subscription('price_solo', '+10 days')->setSubscriptionId('sub_1');
        $integration = $this->integration([
            new JsonMockResponse(['id' => 'sub_1', 'current_period_end' => 1_800_000_000]),
            new JsonMockResponse(['id' => 'sub_1', 'current_period_end' => 1_800_000_000]),
        ]);

        self::assertEquals(new DateTimeImmutable('@1800000000'), $integration->cancelAtPeriodEnd($subscription));
        self::assertEquals(new DateTimeImmutable('@1800000000'), $integration->resume($subscription));
        self::assertSame('true', $this->requests[0]['body']['cancel_at_period_end']);
        self::assertSame('false', $this->requests[1]['body']['cancel_at_period_end']);
    }

    public function testStripesErrorMessageIsKept(): void
    {
        $this->expectException(PaymentIntegrationException::class);
        $this->expectExceptionMessage('No such price: \'price_solo\'');

        $this->integration([new JsonMockResponse(['error' => ['message' => 'No such price: \'price_solo\'']], ['http_code' => 400])])
            ->checkout($this->subscription('price_solo', '+10 days'));
    }

    /**
     * @param list<JsonMockResponse> $responses
     */
    private function integration(array $responses): StripeIntegration
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): JsonMockResponse {
            $body = [];
            parse_str((string) ($options['body'] ?? ''), $body);
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

            return array_shift($responses) ?? self::fail('Unexpected request ' . $method . ' ' . $url);
        }, 'https://api.stripe.com/v1/');

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => 'https://app.test/' . $route);

        return new StripeIntegration(new StripeApi($client), $router, new MockClock('2026-09-28 12:00:00'), 'saas_payment_success');
    }

    private function subscription(string $planId, string $endsIn): Subscription
    {
        $subscription = new Subscription()
            ->setPlan(new Plan()->setName('Solo')->setPlanId($planId)->setPrice(1200))
            ->setStartDate(new DateTimeImmutable('2026-09-20'))
            ->setEndDate(new DateTimeImmutable('2026-09-28 12:00:00')->modify($endsIn));
        new ReflectionProperty(Subscription::class, 'id')->setValue($subscription, new Ulid());

        return $subscription;
    }
}
