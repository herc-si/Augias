<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Onboarding;

use Augias\SaasBundle\Service\SubscriptionService;
use DateTimeInterface;

/**
 * On the hosted service, connecting SUPER PDP costs HERC SI its identity
 * check, then every invoice; and it registers the company in the national
 * directory with SUPER PDP as the platform its invoices are delivered to.
 * A trial nobody has committed to does neither (Rémi, 23/11/2026): the
 * connection waits for a card on file. Subscribing keeps the trial's days —
 * the first payment is still at its end.
 *
 * Nothing is held back outside the service, nor once a card is on file.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Functional\TrialPlatformConnectionTest
 */
final readonly class PlatformConnectionGate
{
    public function __construct(
        private ?SubscriptionService $subscriptions = null,
    ) {
    }

    /**
     * When the connection waits for a card: the day the trial ends, said
     * with the invitation to subscribe. Null when it may go ahead.
     */
    public function waitsForCardUntil(): ?DateTimeInterface
    {
        return $this->subscriptions?->unbilledTrial()?->getEndDate();
    }
}
