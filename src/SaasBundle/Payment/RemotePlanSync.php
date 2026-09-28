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

use Augias\CoreBundle\Telemetry\Telemetry;
use Augias\CoreBundle\Telemetry\TelemetryEvent;
use Psr\Log\LoggerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use function strtolower;

/**
 * Records on the local subscription the plan the payment provider bills.
 *
 * This is the *only* place a paid-plan switch commits to the database. The
 * choose-plan and confirm-plan-change actions defer the local mutation
 * entirely, so that a provider error (a failed checkout, a mis-configured
 * price) cannot leave the app on a plan the user never paid for. The
 * provider is the authority for the switch: this only records it.
 *
 * @see \Augias\SaasBundle\Tests\EventSubscriber\SubscriptionPlanSyncListenerTest
 */
final readonly class RemotePlanSync
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private PlanRepositoryInterface $planRepository,
        private LoggerInterface $logger,
        private Telemetry $telemetry,
    ) {
    }

    /**
     * @param string $remotePlanId the provider's identifier of the plan billed:
     *                             a Lemon Squeezy variant, a Stripe price
     */
    public function apply(Subscription $subscription, string $remotePlanId): void
    {
        if ($remotePlanId === $subscription->getPlan()->getPlanId()) {
            return;
        }

        $targetPlan = $this->planRepository->find($remotePlanId);

        if (! $targetPlan instanceof Plan) {
            $this->logger->warning(
                'Received a subscription webhook for an unknown plan; local plan unchanged.',
                [
                    'subscription_id' => $subscription->getId()->toBase58(),
                    'remote_plan_id' => $remotePlanId,
                ],
            );

            return;
        }

        // Capture whether this is a first conversion (free → paid) before the
        // plan is overwritten, so the telemetry below counts genuine
        // activations and not paid → paid upgrades (e.g. solo → business).
        $isFirstConversion = $subscription->getPlan()->isFree() && ! $targetPlan->isFree();

        // Skip SubscriptionManager::changePlan(): it guards against mutating
        // ACTIVE externally-billed subscriptions, which is exactly the state
        // a provider's webhook reports.
        $subscription->setPlan($targetPlan);
        $this->subscriptionRepository->save($subscription);

        if ($isFirstConversion) {
            $this->telemetry->event(TelemetryEvent::SaasSubscriptionActivated, [
                'plan' => strtolower($targetPlan->getName()),
            ]);
        }
    }
}
