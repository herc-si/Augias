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

use Override;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Entity\WebhookEventLog;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Acts on the Stripe events the webhook endpoint subscribes to.
 *
 * Every `customer.subscription.*` event re-reads its subscription from
 * Stripe (StripeSubscriptionSync). Any other event is acknowledged and left
 * alone: refusing it would only make Stripe send it again.
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\StripeWebhookConsumerTest
 */
#[AsRemoteEventConsumer('stripe')]
final readonly class StripeWebhookConsumer implements ConsumerInterface
{
    public function __construct(
        private StripeSubscriptionSync $sync,
        private RequestStack $requestStack,
    ) {
    }

    #[Override]
    public function consume(RemoteEvent $event): void
    {
        if (! str_starts_with($event->getName(), 'customer.subscription.')) {
            return;
        }

        $payload = $event->getPayload();
        $object = is_array($payload['data'] ?? null) ? ($payload['data']['object'] ?? null) : null;
        $stripeSubscriptionId = is_array($object) ? ($object['id'] ?? null) : null;

        if (! is_string($stripeSubscriptionId)) {
            return;
        }

        $subscription = $this->sync->sync($stripeSubscriptionId);

        $log = $this->requestStack->getCurrentRequest()?->attributes->get('_webhook_event_log');

        if ($log instanceof WebhookEventLog) {
            $log->setEventType($event->getName());
            $log->setGatewayEventId($event->getId());
            if ($subscription instanceof Subscription) {
                $log->setExternalSubscriptionId($subscription->getId()->toBase58());
            }
        }
    }
}
