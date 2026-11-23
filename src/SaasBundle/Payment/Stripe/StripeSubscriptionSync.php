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

use Augias\SaasBundle\Payment\RemotePlanSync;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use Symfony\Component\Uid\Ulid;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Brings a local subscription level with the Stripe subscription it is
 * billed by: its plan, its status, its end date.
 *
 * The webhook only says which subscription changed; its state is read back
 * from Stripe. Stripe does not promise to deliver events in order, and
 * applying what Stripe holds now, whichever event woke us, makes a late or
 * repeated event harmless.
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\StripeSubscriptionSyncTest
 */
final readonly class StripeSubscriptionSync
{
    public function __construct(
        private StripeApi $api,
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private SubscriptionManager $subscriptionManager,
        private RemotePlanSync $planSync,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Subscription|null the local subscription, when there is one
     */
    public function sync(string $stripeSubscriptionId): ?Subscription
    {
        $remote = $this->api->get('subscriptions/' . $stripeSubscriptionId);
        $subscription = $this->findLocal($remote);

        if (! $subscription instanceof Subscription) {
            $this->logger->warning('Stripe subscription with no local counterpart; ignored.', ['stripe_subscription' => $stripeSubscriptionId]);

            return null;
        }

        $status = (string) ($remote['status'] ?? '');
        $current = $subscription->getSubscriptionId();

        // A subscription replaced by a newer checkout still sends its own
        // events: its end must not end the one that took over.
        if ($current !== null && $current !== $stripeSubscriptionId && ! in_array($status, ['active', 'trialing'], true)) {
            return $subscription;
        }

        // Not paid for yet: the first payment is pending or was abandoned.
        if (in_array($status, ['incomplete', 'incomplete_expired'], true)) {
            return $subscription;
        }

        // A second subscription paid while the first is still billed — two
        // checkouts paid one after the other — would bill the company twice
        // every month. The first one stays; the second is cancelled and its
        // payment refunded (Rémi, 23/11/2026).
        if ($current !== null && $current !== $stripeSubscriptionId && $this->stillBilled($current)) {
            $this->cancelDuplicate($remote, $current);

            return $subscription;
        }

        if ($current !== $stripeSubscriptionId) {
            $subscription->setSubscriptionId($stripeSubscriptionId);
        }

        $price = $remote['items']['data'][0]['price']['id'] ?? null;
        if (is_string($price) && $status !== 'canceled') {
            $this->planSync->apply($subscription, $price);
        }

        switch ($status) {
            case 'trialing':
                $this->subscriptionManager->startTrial($subscription, self::date($remote['trial_end'] ?? null));
                break;
            case 'active':
                if (($remote['cancel_at_period_end'] ?? false) === true) {
                    $this->subscriptionManager->cancelSubscription($subscription, StripeIntegration::periodEnd($remote));
                } else {
                    $this->subscriptionManager->renewSubscription($subscription, StripeIntegration::periodEnd($remote));
                }
                break;
            case 'past_due':
                $this->subscriptionManager->markAsPastDue($subscription);
                break;
            case 'unpaid':
                $this->subscriptionManager->markAsUnpaid($subscription);
                break;
            case 'paused':
                $this->subscriptionManager->pauseSubscription($subscription);
                break;
            case 'canceled':
                if ($subscription->hasPendingPlanChange()) {
                    $this->subscriptionManager->applyScheduledPlanChange($subscription);
                } else {
                    $this->subscriptionManager->expireSubscription($subscription);
                }
                break;
            default:
                $this->logger->warning('Unknown Stripe subscription status; ignored.', ['status' => $status]);
        }

        return $subscription;
    }

    private function stillBilled(string $stripeSubscriptionId): bool
    {
        try {
            $remote = $this->api->get('subscriptions/' . $stripeSubscriptionId);
        } catch (PaymentIntegrationException) {
            return false;
        }

        return in_array($remote['status'] ?? null, ['active', 'trialing', 'past_due', 'unpaid', 'paused'], true)
            && ($remote['cancel_at_period_end'] ?? false) !== true;
    }

    /**
     * At error level, so that it reaches the error journal and its alert:
     * HERC SI's invoice for the refunded payment still wants a credit note.
     *
     * @param array<string, mixed> $remote
     */
    private function cancelDuplicate(array $remote, string $kept): void
    {
        $duplicate = (string) ($remote['id'] ?? '');
        $context = ['kept' => $kept, 'cancelled' => $duplicate, 'customer' => $remote['customer'] ?? null];

        try {
            $this->api->delete('subscriptions/' . $duplicate);

            $invoiceId = $remote['latest_invoice'] ?? null;
            $invoice = is_string($invoiceId) ? $this->api->get('invoices/' . $invoiceId) : [];
            $paymentIntent = $invoice['payment_intent'] ?? null;

            if (($invoice['status'] ?? null) === 'paid' && is_string($paymentIntent)) {
                $refund = $this->api->post('refunds', ['payment_intent' => $paymentIntent, 'reason' => 'duplicate']);
                $context['refund'] = $refund['id'] ?? null;
                $context['invoice'] = $invoiceId;
            }
        } catch (PaymentIntegrationException $e) {
            $context['exception'] = $e;
            $this->logger->error('A second Stripe subscription was paid for a company already subscribed, and could not be cancelled or refunded: do it by hand.', $context);

            return;
        }

        $this->logger->error('A second Stripe subscription was paid for a company already subscribed: cancelled and refunded; the credit note follows when Stripe reports the refund.', $context);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function findLocal(array $remote): ?Subscription
    {
        $reference = is_array($remote['metadata'] ?? null) ? ($remote['metadata'][StripeIntegration::METADATA_KEY] ?? null) : null;

        if (is_string($reference)) {
            try {
                $subscription = $this->subscriptionRepository->findOneBy(['id' => Ulid::fromBase58($reference)]);
            } catch (InvalidArgumentException) {
                $subscription = null;
            }

            if ($subscription instanceof Subscription) {
                return $subscription;
            }
        }

        $subscription = $this->subscriptionRepository->findOneBy(['subscriptionId' => (string) ($remote['id'] ?? '')]);

        return $subscription instanceof Subscription ? $subscription : null;
    }

    private static function date(mixed $timestamp): ?DateTimeImmutable
    {
        return is_int($timestamp) ? StripeIntegration::fromTimestamp($timestamp) : null;
    }
}
