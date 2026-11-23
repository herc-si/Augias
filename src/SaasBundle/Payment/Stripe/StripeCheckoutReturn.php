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

use Psr\Log\LoggerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use function is_string;

/**
 * Back from Stripe Checkout: the subscription is read from Stripe at once,
 * rather than when its webhook arrives. Until then a trial just subscribed
 * still looked unbilled, and the electronic invoicing setup the person is
 * sent to next asked them for the card they had just given (Rémi,
 * 23/11/2026).
 *
 * Only the company's own checkout is read: the session must name its
 * subscription. Anything Stripe cannot answer is left to the webhook.
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\StripeCheckoutReturnTest
 */
final readonly class StripeCheckoutReturn
{
    public function __construct(
        private StripeApi $api,
        private StripeSubscriptionSync $sync,
        private LoggerInterface $logger,
    ) {
    }

    public function settle(string $sessionId, Subscription $subscription): void
    {
        try {
            $session = $this->api->get('checkout/sessions/' . $sessionId);
        } catch (PaymentIntegrationException $e) {
            $this->logger->warning('Could not read the Stripe checkout just completed; the webhook will tell.', ['exception' => $e]);

            return;
        }

        $stripeSubscription = $session['subscription'] ?? null;

        if (($session['client_reference_id'] ?? null) !== $subscription->getId()->toBase58() || ! is_string($stripeSubscription)) {
            return;
        }

        try {
            $this->sync->sync($stripeSubscription);
        } catch (PaymentIntegrationException $e) {
            $this->logger->warning('Could not read the Stripe subscription just paid for; the webhook will tell.', ['exception' => $e]);
        }
    }
}
