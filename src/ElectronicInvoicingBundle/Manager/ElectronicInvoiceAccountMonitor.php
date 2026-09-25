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

namespace Augias\ElectronicInvoicingBundle\Manager;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceAccountCheckerInterface;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceAccountStatus;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Psr\Clock\ClockInterface;

/**
 * Asks a platform where it stands on the account, and writes the answer onto
 * the setting — which is what switches electronic invoicing on or off, see
 * {@see ElectronicInvoiceProviderSetting::isUsable()}.
 *
 * Only an answer is written. A platform that cannot be reached says nothing
 * about the account, and an outage must not stop invoices that would have
 * gone through; the last answer stands until there is a new one.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Manager\ElectronicInvoiceAccountMonitorTest
 */
final readonly class ElectronicInvoiceAccountMonitor
{
    public function __construct(
        private ElectronicInvoiceProviderRegistry $providers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Null when the provider cannot be asked. The caller flushes.
     */
    public function check(ElectronicInvoiceProviderSetting $setting): ?ElectronicInvoiceAccountStatus
    {
        $provider = $this->providers->get($setting->getProvider());

        if (! $provider instanceof ElectronicInvoiceAccountCheckerInterface) {
            return null;
        }

        $status = $provider->checkAccount($setting->getSettings());

        if ($status->answered) {
            $setting->recordAccountCheck($status->verification, $this->clock->now());
        }

        return $status;
    }
}
