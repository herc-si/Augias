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

namespace Augias\SaasBundle\EventSubscriber;

use Augias\SaasBundle\Payment\RemotePlanSync;
use SolidWorx\Platform\SaasBundle\Dto\LemonSqueezy\Subscription as LemonSqueezySubscription;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Event\SubscriptionCreatedEvent;
use SolidWorx\Platform\SaasBundle\Event\SubscriptionEvent;
use SolidWorx\Platform\SaasBundle\Event\SubscriptionUpdatedEvent;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listens to Lemon Squeezy `subscription_created` / `subscription_updated`
 * webhook events and synchronises the local subscription's plan with the
 * variant id reported by Lemon Squeezy.
 *
 * The switch itself is recorded by RemotePlanSync, shared with the Stripe
 * webhooks.
 *
 * Webhooks arrive as separate HTTP requests with no user session attached,
 * so the listener is intentionally stateless — it relies purely on the LS
 * payload's `variantId` and the persisted local subscription.
 *
 * Free plans are handled inline by ChoosePlanAction / ConfirmPlanChangeAction
 * — they never round-trip through LS — and active-billed plan changes go
 * through `SubscriptionManager::changeActivePlan()` which is already
 * LS-confirmed before the local update.
 * @see \Augias\SaasBundle\Tests\EventSubscriber\SubscriptionPlanSyncListenerTest
 */
final readonly class SubscriptionPlanSyncListener
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private RemotePlanSync $planSync,
    ) {
    }

    #[AsEventListener(event: SubscriptionCreatedEvent::class)]
    public function onSubscriptionCreated(SubscriptionCreatedEvent $event): void
    {
        $this->sync($event);
    }

    #[AsEventListener(event: SubscriptionUpdatedEvent::class)]
    public function onSubscriptionUpdated(SubscriptionUpdatedEvent $event): void
    {
        $this->sync($event);
    }

    private function sync(SubscriptionEvent $event): void
    {
        $remote = $event->subscription;

        if (! $remote instanceof LemonSqueezySubscription) {
            // Not a Lemon Squeezy payload. Stripe's webhooks never come this
            // way: StripeSubscriptionSync records their plan directly.
            return;
        }

        $subscription = $this->subscriptionRepository->findOneBy(['id' => $event->subscriptionId]);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $this->planSync->apply($subscription, (string) $remote->attributes->variantId);
    }
}
