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

namespace Augias\McpBundle\Mcp\Tool;

use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\SaasBundle\Feature\Feature;
use Mcp\Exception\ToolCallException;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;

/**
 * The limits the screens apply before creating or sending a document, applied
 * to the tools too: a plan's monthly invoice count, the features it leaves out,
 * and the verified address asked for before anything is emailed. On a
 * self-hosted instance the gates let everything through.
 */
final readonly class PlanLimits
{
    public function __construct(
        private FeatureGate $featureGate,
        private InvoiceRepository $invoiceRepository,
        private ClockInterface $clock,
        private EmailVerificationGateInterface $emailVerificationGate,
    ) {
    }

    public function assertCanCreateInvoice(): void
    {
        if (! $this->featureGate->canUse(
            Feature::InvoicesPerMonth->value,
            $this->invoiceRepository->countCreatedInMonth($this->clock->now()),
        )) {
            throw new ToolCallException('The monthly invoice limit of the current plan is reached. Upgrade the plan in Augias to create more invoices this month.');
        }
    }

    public function assertFeature(Feature $feature, string $what): void
    {
        if (! $this->featureGate->isEnabled($feature->value)) {
            throw new ToolCallException(sprintf('%s are not included in the current plan. Upgrade the plan in Augias to use them.', $what));
        }
    }

    public function assertCanSend(): void
    {
        if ($this->emailVerificationGate->isGated()) {
            throw new ToolCallException('The email address of this account is not verified yet. Verify it in Augias before sending documents.');
        }
    }
}
