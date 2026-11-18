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

namespace Augias\ElectronicInvoicingBundle\Checklist;

use Augias\DashboardBundle\Checklist\ChecklistItemInterface;
use Augias\ElectronicInvoicingBundle\Onboarding\ElectronicInvoicingOnboarding;

/**
 * Receiving electronic invoices is required since September 2026: high on
 * the list, done once the platform has verified the company.
 */
final readonly class SetUpElectronicInvoicingItem implements ChecklistItemInterface
{
    public function __construct(
        private ElectronicInvoicingOnboarding $onboarding,
    ) {
    }

    public function getName(): string
    {
        return 'dashboard.checklist.set_up_einvoicing.name';
    }

    public function getDescription(): string
    {
        return 'dashboard.checklist.set_up_einvoicing.description';
    }

    public function getIcon(): string
    {
        return 'tabler:file-certificate';
    }

    public function getRoute(): string
    {
        return '_einvoicing_setup';
    }

    public function getPriority(): int
    {
        return -100;
    }

    public function isComplete(): bool
    {
        return $this->onboarding->progress()->isDone();
    }

    public function active(): bool
    {
        return true;
    }
}
