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

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * The days left of a trial, kept when the company subscribes during it.
 *
 * Subscribing pays at once (Rémi, 26/11/2026): the customer wants their
 * invoice now, and it says what it covers — a year from today plus the days
 * of the trial they had not used, 26/11/2026 to 25/12/2027 for an annual plan
 * taken with 29 days left. Stripe cannot charge now for a period that starts
 * later, so the checkout carries no trial: the days left travel in the Stripe
 * subscription's metadata, and are given back at the end of the first period,
 * as a trial until the date the invoice said. Stripe then bills the next year
 * from that date. A subscription cancelled meanwhile ends there too.
 *
 * A subscription schedule would do the same, but holds every later change of
 * plan or cancellation behind its phases for a year: the trial is set a few
 * days before the renewal instead (augias:saas:carry-over-trials).
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\StripeTrialCarryOverTest
 */
final readonly class StripeTrialCarryOver
{
    /** On the Stripe subscription: the seconds of trial still to give back. */
    public const string SECONDS_LEFT_KEY = 'augias_trial_left';

    /** On the Stripe subscription: they were given back, its trial is paid for. */
    public const string CARRIED_KEY = 'augias_trial_carried';

    /** How long before the renewal the trial is set. */
    public const int LEAD_SECONDS = 3 * 86400;

    public function __construct(
        private StripeApi $api,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The seconds of trial a Stripe subscription (or the metadata of an
     * invoice's subscription) still has to give back; 0 when none.
     *
     * @param array<string, mixed> $metadata
     */
    public static function secondsLeft(array $metadata): int
    {
        $left = $metadata[self::SECONDS_LEFT_KEY] ?? null;

        return (is_string($left) || is_int($left)) && is_numeric($left) && (int) $left > 0 ? (int) $left : 0;
    }

    /**
     * Until when the company has paid: the end of the period Stripe bills,
     * and the days of trial still to come after it.
     *
     * @param array<string, mixed> $remote a Stripe subscription
     */
    public static function paidUntil(array $remote): DateTimeImmutable
    {
        $end = StripeIntegration::periodEnd($remote);
        $left = self::secondsLeft(self::metadata($remote));

        return $left > 0 ? $end->modify('+' . $left . ' seconds') : $end;
    }

    /**
     * A trial Stripe runs on a subscription already paid for: the days
     * given back, not a trial nobody has committed to.
     *
     * @param array<string, mixed> $remote a Stripe subscription
     */
    public static function isCarriedTrial(array $remote): bool
    {
        return (self::metadata($remote)[self::CARRIED_KEY] ?? null) === '1';
    }

    /**
     * Gives the days back once the renewal is near: the subscription's trial
     * runs to the end of the period plus the days left, and nothing is
     * charged or credited for it. Returns Stripe's subscription as it stands
     * after, or null when there was nothing to do yet.
     *
     * @return array<string, mixed>|null
     */
    public function carryOver(string $stripeSubscriptionId): ?array
    {
        $remote = $this->api->get('subscriptions/' . $stripeSubscriptionId);
        $left = self::secondsLeft(self::metadata($remote));

        if ($left === 0 || ! in_array($remote['status'] ?? null, ['active', 'past_due'], true)) {
            return null;
        }

        $end = $remote['current_period_end'] ?? null;
        $now = $this->clock->now()->getTimestamp();

        if (! is_int($end) || $end - $now > self::LEAD_SECONDS) {
            return null;
        }

        // Renewed already (the task did not run in time): the next year is
        // paid, the days would come on top of it. Said, rather than done.
        if ($end <= $now) {
            $this->logger->error('Trial days left were not given back before the renewal.', ['stripe_subscription' => $stripeSubscriptionId, 'seconds_left' => $left]);

            return null;
        }

        try {
            return $this->api->post('subscriptions/' . $stripeSubscriptionId, [
                'trial_end' => $end + $left,
                'proration_behavior' => 'none',
                'metadata' => [self::SECONDS_LEFT_KEY => '', self::CARRIED_KEY => '1'],
            ]);
        } catch (PaymentIntegrationException $e) {
            $this->logger->error('Could not give back the trial days left.', ['stripe_subscription' => $stripeSubscriptionId, 'exception' => $e]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private static function metadata(array $remote): array
    {
        return is_array($remote['metadata'] ?? null) ? $remote['metadata'] : [];
    }
}
