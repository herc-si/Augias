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

namespace Augias\SaasBundle\Command;

use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\StripeSubscriptionSync;
use Augias\SaasBundle\Payment\Stripe\StripeTrialCarryOver;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Repository\SubscriptionRepositoryInterface;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use function count;
use function end;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Gives back the days of trial a company had left when it subscribed, a few
 * days before its first renewal (StripeTrialCarryOver).
 *
 * Read from Stripe, a hundred subscriptions a call: which ones still have
 * days to give back is only written there. Only this instance's
 * subscriptions are touched, should another one share the Stripe account.
 *
 * @see \Augias\SaasBundle\Tests\Command\CarryOverTrialsCommandTest
 */
#[AsCommand(
    name: 'augias:saas:carry-over-trials',
    description: 'Give back, before the first renewal, the trial days left when the company subscribed',
)]
#[AsCronTask('#daily', schedule: 'carry_over_trials')]
final class CarryOverTrialsCommand extends Command
{
    public function __construct(
        private readonly ToggleInterface $toggler,
        private readonly StripeApi $api,
        private readonly StripeTrialCarryOver $carryOver,
        private readonly StripeSubscriptionSync $sync,
        private readonly SubscriptionRepositoryInterface $subscriptions,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        if (! $this->toggler->isActive('saas_enabled')) {
            $this->io->note('Not the hosted service: nothing to do.');

            return self::SUCCESS;
        }

        $carried = 0;
        $query = ['status' => 'active', 'limit' => 100];

        try {
            do {
                $page = $this->api->get('subscriptions', $query);
                $data = is_array($page['data'] ?? null) ? $page['data'] : [];

                foreach ($data as $remote) {
                    if (is_array($remote) && $this->carry($remote)) {
                        ++$carried;
                    }
                }

                $last = $data === [] ? null : end($data);
                $query['starting_after'] = is_array($last) ? ($last['id'] ?? null) : null;
            } while (($page['has_more'] ?? false) === true && is_string($query['starting_after']) && count($data) > 0);
        } catch (PaymentIntegrationException $e) {
            $this->io->error(sprintf('Stripe could not list the subscriptions: %s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->io->success(sprintf('Gave back the trial days of %d subscription(s).', $carried));

        return self::SUCCESS;
    }

    /**
     * @param array<mixed> $remote
     */
    private function carry(array $remote): bool
    {
        $id = $remote['id'] ?? null;
        $metadata = is_array($remote['metadata'] ?? null) ? $remote['metadata'] : [];

        if (! is_string($id) || StripeTrialCarryOver::secondsLeft($metadata) === 0) {
            return false;
        }

        if (! $this->subscriptions->findOneBy(['subscriptionId' => $id]) instanceof Subscription) {
            return false;
        }

        if ($this->carryOver->carryOver($id) === null) {
            return false;
        }

        // At once, rather than when the webhook arrives: the end date the
        // company reads stays the one its invoice said.
        $this->sync->sync($id);

        return true;
    }
}
