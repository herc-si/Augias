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

namespace Augias\ElectronicInvoicingBundle\Provider;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Resolves the company's active provider setting and dispatches to the
 * matching tagged ElectronicInvoiceProviderInterface — the equivalent of
 * NotificationBundle's NotificationTransportFactory for this bundle.
 */
final readonly class ElectronicInvoiceProviderRegistry
{
    /**
     * @param ServiceLocator<ElectronicInvoiceProviderInterface> $providers
     */
    public function __construct(
        #[AutowireLocator(ElectronicInvoiceProviderInterface::DI_TAG)]
        private ServiceLocator $providers,
        private ElectronicInvoiceProviderSettingRepository $settingRepository,
    ) {
    }

    public function hasActiveProvider(): bool
    {
        return $this->settingRepository->findActive() instanceof ElectronicInvoiceProviderSetting;
    }

    /**
     * Resolves a provider by its machine name regardless of whether it is the
     * company's currently active one — a submission keeps referencing the
     * provider it was sent through even after the active provider changes.
     */
    public function get(string $name): ?ElectronicInvoiceProviderInterface
    {
        if (! $this->providers->has($name)) {
            return null;
        }

        /** @var ElectronicInvoiceProviderInterface */
        return $this->providers->get($name);
    }

    /**
     * Resolves $name to its {@see ElectronicInvoiceReceiverInterface} — null both
     * when the provider doesn't exist and when it exists but doesn't support
     * receiving invoices, so callers don't need two separate checks.
     */
    public function getReceiver(string $name): ?ElectronicInvoiceReceiverInterface
    {
        $provider = $this->get($name);

        return $provider instanceof ElectronicInvoiceReceiverInterface ? $provider : null;
    }

    public function send(Invoice | CreditNote $invoice): ElectronicInvoiceSubmissionResult
    {
        $setting = $this->settingRepository->findActive();

        if (! $setting instanceof ElectronicInvoiceProviderSetting) {
            throw new LogicException('No active electronic invoicing provider is configured.');
        }

        if (! $this->providers->has($setting->getProvider())) {
            throw new LogicException(sprintf('Unknown electronic invoicing provider "%s".', $setting->getProvider()));
        }

        /** @var ElectronicInvoiceProviderInterface $provider */
        $provider = $this->providers->get($setting->getProvider());

        return $provider->send($invoice, $setting->getSettings());
    }
}
