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

use Augias\CoreBundle\Entity\Company;
use Augias\SettingsBundle\SystemConfig;
use Carbon\CarbonInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
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

    /** On the Stripe customer: the company it pays for. */
    public const string COMPANY_METADATA_KEY = 'augias_company';

    /** Stripe refuses a trial that ends sooner than this at checkout. */
    private const int MINIMUM_TRIAL_SECONDS = 48 * 3600;

    public function __construct(
        private StripeApi $api,
        private UrlGeneratorInterface $router,
        private ClockInterface $clock,
        #[Autowire(param: 'solidworx_platform.saas.payment.return_route')]
        private string $returnRoute,
        private ?SystemConfig $systemConfig = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The Stripe customer of the company, found by the company it carries in
     * its metadata, or made — with the company's name, address and email as
     * Augias holds them now. Null when Stripe cannot be asked: the checkout
     * then opens empty, as before, rather than not at all.
     */
    private function customer(Company $company, ?string $email): ?string
    {
        $reference = $company->getId()->toBase58();
        $details = ['name' => $company->getName(), 'metadata' => [self::COMPANY_METADATA_KEY => $reference]];

        if ($email !== null) {
            $details['email'] = $email;
        }

        $address = $this->address($company);

        if ($address !== null) {
            $details['address'] = $address;
        }

        try {
            $existing = $this->existingCustomer($reference, $email);

            $customer = is_string($existing)
                ? $this->api->post('customers/' . $existing, $details)
                : $this->api->post('customers', $details);
        } catch (PaymentIntegrationException $e) {
            $this->logger?->warning('Could not prepare the Stripe customer: the checkout opens without its details.', ['exception' => $e]);

            return null;
        }

        return is_string($customer['id'] ?? null) ? $customer['id'] : null;
    }

    /**
     * The customer already made for the company. Listing by email comes
     * first: unlike search, which Stripe indexes up to a minute late (an
     * hour in an outage), a list sees a customer made a moment ago — the
     * subscriber who leaves the checkout and comes straight back does not
     * get a second one. Search then finds it under another email.
     *
     * @throws PaymentIntegrationException
     */
    private function existingCustomer(string $reference, ?string $email): ?string
    {
        if ($email !== null) {
            foreach ($this->list($this->api->get('customers', ['email' => $email, 'limit' => 100]), 'data') as $customer) {
                if (($customer['metadata'][self::COMPANY_METADATA_KEY] ?? null) === $reference && is_string($customer['id'] ?? null)) {
                    return $customer['id'];
                }
            }
        }

        $found = $this->api->get('customers/search', ['query' => sprintf("metadata['%s']:'%s'", self::COMPANY_METADATA_KEY, $reference), 'limit' => 1]);
        $id = $this->list($found, 'data')[0]['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function address(Company $company): ?array
    {
        $raw = $this->systemConfig?->get('system/company/contact_details/address', $company);

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['street1'] ?? null) || $decoded['street1'] === '') {
            return null;
        }

        return array_filter([
            'line1' => $decoded['street1'],
            'line2' => is_string($decoded['street2'] ?? null) ? $decoded['street2'] : '',
            'postal_code' => is_string($decoded['zip'] ?? null) ? $decoded['zip'] : '',
            'city' => is_string($decoded['city'] ?? null) ? $decoded['city'] : '',
            'state' => is_string($decoded['state'] ?? null) ? $decoded['state'] : '',
            'country' => is_string($decoded['country'] ?? null) ? strtoupper($decoded['country']) : '',
        ], static fn (string $value): bool => $value !== '');
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
        $email = is_string($email) && $email !== '' ? $email : null;
        $subscriber = $subscription->getSubscriber();
        $customer = $subscriber instanceof Company ? $this->customer($subscriber, $email) : null;

        if ($customer !== null) {
            // The page opens with what Augias knows of the company — its
            // name and address — and what is typed there is kept on the
            // customer, for the next payment (Rémi, 19/11/2026).
            $parameters['customer'] = $customer;
            $parameters['customer_update'] = ['address' => 'auto', 'name' => 'auto'];
        } elseif ($email !== null) {
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
