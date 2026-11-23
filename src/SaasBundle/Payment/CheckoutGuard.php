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

namespace Augias\SaasBundle\Payment;

use DateTimeImmutable;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;

/**
 * Whether a subscription may go through a checkout, which opens a new
 * subscription at the payment provider. Once one is billed there, a second
 * checkout would bill the company twice for the same months (Rémi,
 * 23/11/2026): a change of plan or of period goes through the subscription
 * that exists, a late payment is settled on it.
 *
 * @see \Augias\SaasBundle\Tests\Payment\CheckoutGuardTest
 */
final class CheckoutGuard
{
    /**
     * The translation key of the reason, or null when a checkout may open.
     */
    public static function refusal(Subscription $subscription, DateTimeImmutable $now): ?string
    {
        if (! $subscription->isExternallyBilled()) {
            return null;
        }

        return match ($subscription->getStatus()) {
            SubscriptionStatus::ACTIVE, SubscriptionStatus::TRIAL, SubscriptionStatus::PAUSED => 'saas.flash.checkout_already_subscribed',
            SubscriptionStatus::PAST_DUE, SubscriptionStatus::UNPAID => 'saas.flash.checkout_settle_first',
            // Cancelled at the period's end: still paid for until then.
            SubscriptionStatus::CANCELLED => $subscription->getEndDate() > $now ? 'saas.flash.checkout_still_running' : null,
            default => null,
        };
    }
}
