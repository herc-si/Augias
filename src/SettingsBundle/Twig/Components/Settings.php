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

namespace Augias\SettingsBundle\Twig\Components;

use Augias\CoreBundle\Response\FlashResponse;
use Augias\SaasBundle\Service\SubscriptionService;
use Augias\SettingsBundle\Entity\Setting;
use Augias\SettingsBundle\Form\Type\SettingsType;
use Augias\SettingsBundle\Repository\SettingsRepository;
use Augias\UserBundle\Security\CompanyAccess;
use Augias\UserBundle\Security\SupportPass;
use Generator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PreMount;
use Throwable;
use function array_key_first;
use function is_array;
use function str_replace;

/**
 * @see \Augias\SettingsBundle\Tests\Twig\Components\SettingsTest
 */
#[AsLiveComponent]
final class Settings extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    /**
     * @var array<string, string>
     */
    public array $settingIconMap = [
        'company' => 'building',
        'invoice' => 'file-invoice',
        'quote' => 'file-text-o',
        'email' => 'envelope',
        'payment' => 'credit-card',
        'tax' => 'balance-scale',
        'system' => 'cog',
    ];

    #[LiveProp(writable: true, onUpdated: 'onSectionChange', url: true)]
    public string $section = '';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly string $customDomainDnsRecord = '',
        private readonly ?CompanyAccess $access = null,
    ) {
    }

    #[ExposeInTemplate('custom_domain_dns_record')]
    public function getCustomDomainDnsRecord(): string
    {
        return $this->customDomainDnsRecord;
    }

    #[PreMount()]
    public function preMount(): void
    {
        $this->section = key($this->getAppSettings());
    }

    /**
     * @return array<string, string|bool>
     */
    #[ExposeInTemplate]
    public function getAppSettings(bool $useObject = false): array
    {
        $settings = [];

        // Ordered explicitly: this array is built by inserting keys in row order, so the row
        // order decides both the section tabs (and which one preMount() selects by default)
        // and the order of the fields within a section. A plain findAll() leaves that to the
        // database, and PostgreSQL gives no guarantee at all - an UPDATE relocates the row,
        // so simply saving a setting reshuffled the tabs.
        //
        // Ordering by the ULID id keeps the settings in creation order, which is the order
        // the config providers seed them in and the order the UI has always displayed.
        // Ordering by key would be alphabetical and would reorder the tabs.
        /** @var Setting $setting */
        foreach ($this->settingsRepository->findBy([], ['id' => 'ASC']) as $setting) {
            $path = '[' . str_replace('/', '][', $setting->getKey()) . ']';

            $value = $setting->getType() === CheckboxType::class ? $setting->getValue() === '1' : $setting->getValue();

            $this->propertyAccessor->setValue(
                $settings,
                $path,
                $useObject ? $setting : $value
            );
        }

        // Someone let in to help reads the settings, but not the company's
        // credentials with its mail provider: they are not needed to see what
        // was configured, and they are the one thing here worth taking.
        if ($this->access?->pass() instanceof SupportPass && is_array($settings['email'] ?? null)) {
            unset($settings['email']['sending_options']);
        }

        return $settings;
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $isTrialSubscription = $this->subscriptionService?->isTrialSubscription() ?? false;
        $appSettings = $this->getAppSettings(false);

        if (! isset($appSettings[$this->section])) {
            $this->section = (string) array_key_first($appSettings);
        }

        return $this->createForm(
            SettingsType::class,
            $appSettings[$this->section],
            [
                'settings' => $this->getAppSettings(true)[$this->section],
                'subscription_in_trial' => $isTrialSubscription,
            ]
        );
    }

    public function onSectionChange(): void
    {
        $this->resetForm();
    }

    /**
     * @phpstan-ignore-next-line Overriding method from trait
     */
    private function getDataModelValue(): string
    {
        return 'norender|*';
    }

    /**
     * @throws Throwable
     */
    #[LiveAction]
    public function save(Request $request): RedirectResponse
    {
        $files = $request->files->all();

        if (isset($files['settings']['company']['logo'])) {
            $this->formValues['company']['logo'] = $files['settings']['company']['logo'];
        }

        $this->submitForm();

        $this->settingsRepository->store([$this->section => $this->getForm()->getData()]);

        $route = $this->generateUrl('_settings', ['section' => $this->section]);

        return new class($route) extends RedirectResponse implements FlashResponse {
            /**
             * @return Generator<string, string>
             */
            public function getFlash(): Generator
            {
                yield self::FLASH_SUCCESS => 'settings.saved.success';
            }
        };
    }
}
