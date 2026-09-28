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

namespace Augias\SaasBundle\Payment\Stripe;

use Carbon\CarbonInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Override;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\SaasBundle\Dto\IntegrationProduct;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\Options;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function array_filter;
use function array_values;
use function date_default_timezone_get;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Subscriptions collected by Stripe, by card or SEPA direct debit.
 *
 * HERC SI sells the subscription itself: Stripe only takes the payments and
 * runs the renewals. The plans are Stripe prices, whose id is the plan's
 * planId; the local subscription travels in the Stripe subscription's
 * metadata, which is how the webhooks find it again (StripeSubscriptionSync).
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\StripeIntegrationTest
 */
final readonly class StripeIntegration implements PaymentIntegrationInterface
{
    public const string METADATA_KEY = 'subscription_id';

    /** Stripe refuses a trial that ends sooner than this at checkout. */
    private const int MINIMUM_TRIAL_SECONDS = 48 * 3600;

    public function __construct(
        private StripeApi $api,
        private UrlGeneratorInterface $router,
        private ClockInterface $clock,
        #[Autowire(param: 'solidworx_platform.saas.payment.return_route')]
        private string $returnRoute,
    ) {
    }

    #[Override]
    public function getPlans(): iterable
    {
        $prices = $this->api->get('prices', [
            'active' => 'true',
            'type' => 'recurring',
            'limit' => 100,
            'expand' => ['data.product'],
        ]);

        foreach ($this->list($prices, 'data') as $price) {
            $product = $price['product'] ?? null;
            $recurring = $price['recurring'] ?? null;

            if (! is_array($product) || ! ($product['active'] ?? false) || ! is_array($recurring) || ! is_int($price['unit_amount'] ?? null)) {
                continue;
            }

            yield new IntegrationProduct(
                id: (string) $price['id'],
                name: (string) $product['name'],
                description: (string) ($product['description'] ?? ''),
                price: $price['unit_amount'],
                interval: CarbonInterval::fromString(sprintf('%d %s', $recurring['interval_count'] ?? 1, $recurring['interval'] ?? 'month')),
            );
        }
    }

    #[Override]
    public function checkout(Subscription $subscription, ?Options $options = null): string
    {
        $reference = $subscription->getId()->toBase58();

        $parameters = [
            'mode' => 'subscription',
            'line_items' => [['price' => $subscription->getPlan()->getPlanId(), 'quantity' => 1]],
            'payment_method_types' => ['card', 'sepa_debit'],
            'billing_address_collection' => 'required',
            'allow_promotion_codes' => 'true',
            'locale' => 'auto',
            'client_reference_id' => $reference,
            'subscription_data' => ['metadata' => [self::METADATA_KEY => $reference]],
            'success_url' => $this->router->generate($this->returnRoute, referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $this->router->generate('billing_index', referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        $email = $options?->getValue(Options::EMAIL);
        if (is_string($email) && $email !== '') {
            $parameters['customer_email'] = $email;
        }

        // What is left of the local trial carries over: the card is taken
        // now, the first payment when the trial ends.
        $trialEnd = $subscription->getEndDate()->getTimestamp();
        if ($options?->getValue(Options::SKIP_TRIAL) === false && $trialEnd - $this->clock->now()->getTimestamp() >= self::MINIMUM_TRIAL_SECONDS) {
            $parameters['subscription_data']['trial_end'] = $trialEnd;
        }

        $session = $this->api->post('checkout/sessions', $parameters);

        if (! is_string($session['url'] ?? null)) {
            throw new PaymentIntegrationException(sprintf('Stripe returned no checkout URL for subscription "%s".', $reference));
        }

        return $session['url'];
    }

    #[Override]
    public function getCustomerPortalUrl(Subscription $subscription): string
    {
        $remote = $this->api->get('subscriptions/' . $this->requireSubscriptionId($subscription));

        $session = $this->api->post('billing_portal/sessions', [
            'customer' => (string) $remote['customer'],
            'return_url' => $this->router->generate('billing_index', referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        if (! is_string($session['url'] ?? null)) {
            throw new PaymentIntegrationException('Stripe returned no customer portal URL.');
        }

        return $session['url'];
    }

    #[Override]
    public function changePlan(Subscription $subscription, Plan $newPlan): DateTimeImmutable
    {
        $subscriptionId = $this->requireSubscriptionId($subscription);
        $remote = $this->api->get('subscriptions/' . $subscriptionId);
        $item = $this->list($remote['items'] ?? [], 'data')[0] ?? null;

        if ($item === null) {
            throw new PaymentIntegrationException(sprintf('Stripe subscription "%s" has no item to change.', $subscriptionId));
        }

        return self::periodEnd($this->api->post('subscriptions/' . $subscriptionId, [
            'items' => [['id' => (string) $item['id'], 'price' => $newPlan->getPlanId()]],
            // Charged the difference now, as Lemon Squeezy did (invoice_immediately).
            'proration_behavior' => 'always_invoice',
        ]));
    }

    #[Override]
    public function cancelAtPeriodEnd(Subscription $subscription): DateTimeImmutable
    {
        return self::periodEnd($this->api->post('subscriptions/' . $this->requireSubscriptionId($subscription), [
            'cancel_at_period_end' => 'true',
        ]));
    }

    #[Override]
    public function resume(Subscription $subscription): DateTimeImmutable
    {
        return self::periodEnd($this->api->post('subscriptions/' . $this->requireSubscriptionId($subscription), [
            'cancel_at_period_end' => 'false',
        ]));
    }

    /**
     * @param array<string, mixed> $remote a Stripe subscription
     */
    public static function periodEnd(array $remote): DateTimeImmutable
    {
        $end = $remote['current_period_end'] ?? null;

        if (! is_int($end)) {
            throw new PaymentIntegrationException(sprintf('Stripe subscription "%s" has no current_period_end.', (string) ($remote['id'] ?? '?')));
        }

        return self::fromTimestamp($end);
    }

    /**
     * A Stripe timestamp, in the application's time zone: the subscription
     * columns keep no offset on MySQL or SQLite, and read their value back in
     * that zone. Stored in UTC, an end date would move by the offset.
     */
    public static function fromTimestamp(int $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $timestamp)->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private function requireSubscriptionId(Subscription $subscription): string
    {
        $subscriptionId = $subscription->getSubscriptionId();

        if ($subscriptionId === null || $subscriptionId === '') {
            throw new InvalidArgumentException(sprintf('Subscription "%s" has no Stripe subscription id.', $subscription->getId()->toBase58()));
        }

        return $subscriptionId;
    }

    /**
     * @param mixed $object a Stripe list object, or anything else
     *
     * @return list<array<string, mixed>>
     */
    private function list(mixed $object, string $key): array
    {
        $items = is_array($object) ? ($object[$key] ?? []) : [];

        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }
}
