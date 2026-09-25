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

namespace Augias\ElectronicInvoicingBundle\Twig\Components;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceAccountMonitor;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderInterface;
use Augias\ElectronicInvoicingBundle\Provider\ProviderMetadataProvider;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use function array_filter;
use function array_values;
use function str_contains;
use function strtolower;
use function usort;

/**
 * @see \Augias\ElectronicInvoicingBundle\Tests\Twig\Components\ElectronicInvoiceMarketplaceTest
 */
#[AsLiveComponent]
final class ElectronicInvoiceMarketplace extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $selectedProvider = '';

    #[LiveProp(writable: true, url: true)]
    public string $selectedSetting = '';

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    /**
     * @param ServiceLocator<ElectronicInvoiceProviderInterface> $providers
     */
    public function __construct(
        #[AutowireLocator(ElectronicInvoiceProviderInterface::DI_TAG)]
        private readonly ServiceLocator $providers,
        private readonly ProviderMetadataProvider $metadataProvider,
        private readonly ElectronicInvoiceProviderSettingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ElectronicInvoiceAccountMonitor $accountMonitor,
    ) {
    }

    /**
     * @return list<array{name: string, displayName: string, tagline: string, icon: string, recommended: bool, isConfigured: bool}>
     */
    #[ExposeInTemplate]
    public function availableProviders(): array
    {
        $providers = [];

        foreach (array_keys($this->providers->getProvidedServices()) as $name) {
            try {
                $this->providers->get($name);
            } catch (NotFoundExceptionInterface | ContainerExceptionInterface) {
                continue;
            }

            $info = $this->metadataProvider->get($name);

            $providers[] = [
                'name' => $name,
                'displayName' => $info->displayName,
                'tagline' => $info->tagline,
                'icon' => $info->icon,
                'recommended' => $info->recommended,
                'isConfigured' => $this->isProviderConfigured($name),
            ];
        }

        usort(
            $providers,
            static fn (array $a, array $b): int => ($b['recommended'] <=> $a['recommended'])
                ?: ($a['displayName'] <=> $b['displayName']),
        );

        return $providers;
    }

    /**
     * @return list<array{name: string, displayName: string, tagline: string, icon: string, recommended: bool, isConfigured: bool}>
     */
    #[ExposeInTemplate]
    public function filteredProviders(): array
    {
        if ($this->searchQuery === '') {
            return $this->availableProviders();
        }

        $query = strtolower($this->searchQuery);

        return array_values(array_filter(
            $this->availableProviders(),
            static fn (array $provider): bool => str_contains(strtolower($provider['displayName']), $query)
                || str_contains(strtolower($provider['tagline']), $query),
        ));
    }

    /**
     * @return list<ElectronicInvoiceProviderSetting>
     */
    #[ExposeInTemplate]
    public function configuredProviders(): array
    {
        // CompanyFilter (the global Doctrine filter) already scopes this to
        // the active company, same as every other CompanyAware repository
        // query in the app — no need to filter by company explicitly here.
        return $this->repository->findBy([], ['name' => 'ASC']);
    }

    private function isProviderConfigured(string $name): bool
    {
        return $this->repository->findOneBy(['provider' => $name]) instanceof ElectronicInvoiceProviderSetting;
    }

    #[LiveAction]
    public function clearSearch(): void
    {
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->selectedProvider = '';
        $this->selectedSetting = '';
    }

    #[LiveAction]
    public function setActive(#[LiveArg] ElectronicInvoiceProviderSetting $setting): void
    {
        foreach ($this->repository->findAll() as $other) {
            $other->setActive($other === $setting);
        }

        // Asked on the spot: the badge has to say at once whether invoices
        // will go through it, or wait for the platform to verify the account.
        $this->accountMonitor->check($setting);

        $this->entityManager->flush();
    }
}
