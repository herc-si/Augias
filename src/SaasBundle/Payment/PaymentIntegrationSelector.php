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

use Augias\SaasBundle\Payment\Stripe\StripeIntegration;
use DateTimeImmutable;
use Override;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Integration\LemonSqueezy;
use SolidWorx\Platform\SaasBundle\Integration\Options;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use UnexpectedValueException;
use function sprintf;

/**
 * The payment provider the hosted service collects subscriptions with,
 * chosen by AUGIAS_SAAS_PAYMENT_PROVIDER: Stripe, or Lemon Squeezy as the
 * project inherited it.
 *
 * @see \Augias\SaasBundle\Tests\Payment\PaymentIntegrationSelectorTest
 */
final readonly class PaymentIntegrationSelector implements PaymentIntegrationInterface
{
    public function __construct(
        #[Autowire(env: 'AUGIAS_SAAS_PAYMENT_PROVIDER')]
        private string $provider,
        private StripeIntegration $stripe,
        private LemonSqueezy $lemonSqueezy,
    ) {
    }

    #[Override]
    public function checkout(Subscription $subscription, ?Options $options = null): string
    {
        return $this->selected()->checkout($subscription, $options);
    }

    #[Override]
    public function getPlans(): iterable
    {
        return $this->selected()->getPlans();
    }

    #[Override]
    public function getCustomerPortalUrl(Subscription $subscription): string
    {
        return $this->selected()->getCustomerPortalUrl($subscription);
    }

    #[Override]
    public function changePlan(Subscription $subscription, Plan $newPlan): DateTimeImmutable
    {
        return $this->selected()->changePlan($subscription, $newPlan);
    }

    #[Override]
    public function cancelAtPeriodEnd(Subscription $subscription): DateTimeImmutable
    {
        return $this->selected()->cancelAtPeriodEnd($subscription);
    }

    #[Override]
    public function resume(Subscription $subscription): DateTimeImmutable
    {
        return $this->selected()->resume($subscription);
    }

    private function selected(): PaymentIntegrationInterface
    {
        return match ($this->provider) {
            'stripe' => $this->stripe,
            'lemon_squeezy' => $this->lemonSqueezy,
            default => throw new UnexpectedValueException(sprintf('Unknown AUGIAS_SAAS_PAYMENT_PROVIDER "%s": expected "stripe" or "lemon_squeezy".', $this->provider)),
        };
    }
}
