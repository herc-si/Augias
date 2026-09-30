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

namespace Augias\NotificationBundle\Twig\Components;

use Augias\NotificationBundle\Configurator\ConfiguratorInterface;
use Augias\NotificationBundle\Entity\TransportSetting;
use Augias\NotificationBundle\Factory\NotificationTransportFactory;
use Augias\NotificationBundle\Repository\TransportSettingRepository;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Throwable;
use function array_filter;
use function array_values;
use function in_array;
use function str_contains;
use function str_replace;
use function strtolower;
use function ucwords;
use function usort;

/**
 * @see \Augias\NotificationBundle\Tests\Twig\Components\NotificationMarketplaceTest
 */
#[AsLiveComponent]
final class NotificationMarketplace extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $view = 'marketplace';

    #[LiveProp(writable: true, url: true)]
    public string $selectedIntegration = '';

    #[LiveProp(writable: true, url: true)]
    public string $transport = '';

    #[LiveProp(writable: true, url: true)]
    public string $type = '';

    #[LiveProp(writable: true, url: true)]
    public string $activeTab = 'sms';

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    /**
     * @param ServiceLocator<ConfiguratorInterface> $transportConfigurations
     */
    public function __construct(
        #[AutowireLocator(ConfiguratorInterface::DI_TAG)]
        private readonly ServiceLocator $transportConfigurations,
        private readonly TransportSettingRepository $repository,
        private readonly TranslatorInterface $translator,
        // The chat factory as NotificationTransportConfigCompilerPass decorates
        // it: the class alone is not a service of its own.
        #[Autowire(service: 'chatter.transport_factory')]
        private readonly NotificationTransportFactory $transportFactory,
    ) {
    }

    /**
     * @return list<array{name: string, displayName: string, type: string, typeLabel: string, description: string, icon: string, isPopular: bool, isConfigured: bool}>
     */
    #[ExposeInTemplate]
    public function availableIntegrations(): array
    {
        $integrations = [];

        foreach (array_keys($this->transportConfigurations->getProvidedServices()) as $name) {
            try {
                /** @var ConfiguratorInterface $configurator */
                $configurator = $this->transportConfigurations->get($name);
                $type = $configurator::getType();

                $integrations[] = [
                    'name' => $name,
                    'displayName' => $this->humanizeIntegrationName($name),
                    'type' => $type,
                    'typeLabel' => $type === 'texter' ? 'SMS' : 'Chat',
                    'description' => $this->getIntegrationDescription($name, $type),
                    'icon' => $this->getIntegrationIcon($name),
                    'isPopular' => $this->isPopularIntegration($name),
                    'isConfigured' => $this->isIntegrationConfigured($name),
                ];
            } catch (NotFoundExceptionInterface | ContainerExceptionInterface) {
                continue;
            }
        }

        // Sort: popular first, then alphabetical
        usort(
            $integrations,
            static fn ($a, $b) =>
            // Popular integrations first
            ($b['isPopular'] <=> $a['isPopular']) ?:
            // Then alphabetical
            ($a['displayName'] <=> $b['displayName'])
        );

        return $integrations;
    }

    /**
     * @return list<TransportSetting>
     */
    #[ExposeInTemplate]
    public function configuredIntegrations(): array
    {
        return $this->repository->findBy(['user' => $this->getUser()], ['name' => 'ASC']);
    }

    /**
     * @return list<array{name: string, displayName: string, type: string, typeLabel: string, description: string, icon: string, isPopular: bool, isConfigured: bool}>
     */
    #[ExposeInTemplate]
    public function filteredIntegrations(): array
    {
        if ($this->searchQuery === '') {
            return $this->availableIntegrations();
        }

        $query = strtolower($this->searchQuery);

        return array_values(array_filter(
            $this->availableIntegrations(),
            static fn ($integration) => str_contains(strtolower((string) $integration['displayName']), $query) ||
                           str_contains(strtolower((string) $integration['description']), $query) ||
                           str_contains(strtolower((string) $integration['typeLabel']), $query)
        ));
    }

    /**
     * @return list<array{name: string, displayName: string, type: string, typeLabel: string, description: string, icon: string, isPopular: bool, isConfigured: bool}>
     */
    #[ExposeInTemplate]
    public function tabFilteredIntegrations(): array
    {
        $filtered = $this->filteredIntegrations();

        $targetType = match ($this->activeTab) {
            'sms' => 'texter',
            'chat' => 'chatter',
            default => 'texter',
        };

        return array_values(array_filter($filtered, fn ($integration) => $integration['type'] === $targetType));
    }

    /**
     * @return list<array{name: string, displayName: string, type: string, typeLabel: string, description: string, icon: string, isPopular: bool, isConfigured: bool}>
     */
    #[ExposeInTemplate]
    public function featuredIntegrations(): array
    {
        return array_values(array_filter($this->tabFilteredIntegrations(), fn ($integration) => $integration['isPopular']));
    }

    /**
     * @return list<array{name: string, displayName: string, type: string, typeLabel: string, description: string, icon: string, isPopular: bool, isConfigured: bool}>
     */
    #[ExposeInTemplate]
    public function regularIntegrations(): array
    {
        return array_values(array_filter($this->tabFilteredIntegrations(), fn ($integration) => ! $integration['isPopular']));
    }

    public function getIntegrationIcon(string $name): string
    {
        return match ($name) {
            // SMS Providers
            'Twilio' => 'tabler:message-2',
            'Vonage' => 'tabler:phone',
            'AmazonSns' => 'tabler:brand-aws',
            'MessageBird', 'MicrosoftTeams' => 'tabler:message-circle',
            'Infobip' => 'tabler:message',
            'Telnyx' => 'tabler:phone-call',
            'Sinch' => 'tabler:device-mobile',
            'Clickatell', 'Zulip' => 'tabler:messages',
            'Brevo' => 'tabler:mail',
            'Mailjet' => 'tabler:mail-forward',
            'FakeSms', 'FakeChat' => 'tabler:code',

            // Chat Providers
            'Slack' => 'tabler:brand-slack',
            'Discord' => 'tabler:brand-discord',
            'Telegram' => 'tabler:brand-telegram',
            'GoogleChat' => 'tabler:brand-google',
            'Mattermost' => 'tabler:message-circle-2',
            'RocketChat' => 'tabler:rocket',
            'LinkedIn' => 'tabler:brand-linkedin',
            'Firebase' => 'tabler:brand-firebase',
            'Mercure' => 'tabler:speakerphone',

            // Generic fallbacks
            default => 'tabler:bell',
        };
    }

    public function getIntegrationTypeLabel(string $transportName): string
    {
        try {
            /** @var ConfiguratorInterface $configurator */
            $configurator = $this->transportConfigurations->get($transportName);
            $type = $configurator::getType();

            return $this->translator->trans($type === 'texter' ? 'notification.type.sms' : 'notification.type.chat');
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface) {
            return $this->translator->trans('notification.type.unknown');
        }
    }

    public function getIntegrationType(string $transportName): string
    {
        try {
            /** @var ConfiguratorInterface $configurator */
            $configurator = $this->transportConfigurations->get($transportName);

            return $configurator::getType();
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface) {
            return 'texter';
        }
    }

    private function humanizeIntegrationName(string $name): string
    {
        // Sanitize integration name to prevent XSS - only keep alphanumeric, underscores, and hyphens
        $sanitized = preg_replace('/[^a-z0-9_-]/i', '', $name);

        return ucwords(str_replace(['_', '-'], ' ', $sanitized));
    }

    private function getIntegrationDescription(string $name, string $type): string
    {
        $descriptionKey = match ($name) {
            'Twilio' => 'notification.provider.twilio',
            'Vonage' => 'notification.provider.vonage',
            'AmazonSns' => 'notification.provider.amazon_sns',
            'MessageBird' => 'notification.provider.message_bird',
            'Sinch' => 'notification.provider.sinch',
            'Slack' => 'notification.provider.slack',
            'Discord' => 'notification.provider.discord',
            'Telegram' => 'notification.provider.telegram',
            'MicrosoftTeams' => 'notification.provider.microsoft_teams',
            'GoogleChat' => 'notification.provider.google_chat',
            'Mattermost' => 'notification.provider.mattermost',
            'RocketChat' => 'notification.provider.rocket_chat',
            'FakeSms' => 'notification.provider.fake_sms',
            'FakeChat' => 'notification.provider.fake_chat',
            default => null,
        };

        if ($descriptionKey !== null) {
            return $this->translator->trans($descriptionKey);
        }

        $typeLabel = $type === 'texter'
            ? $this->translator->trans('notification.type.sms')
            : $this->translator->trans('notification.provider.type_chat');

        return $this->translator->trans('notification.provider.generic', [
            '%type%' => $typeLabel,
            '%name%' => $this->humanizeIntegrationName($name),
        ]);
    }

    private function isPopularIntegration(string $name): bool
    {
        return in_array($name, [
            'Twilio',
            'Vonage',
            'Slack',
            'Discord',
            'Telegram',
            'MicrosoftTeams',
        ], true);
    }

    private function isIntegrationConfigured(string $name): bool
    {
        return $this->repository->findOneBy([
            'transport' => $name,
            'user' => $this->getUser(),
        ]) instanceof TransportSetting;
    }

    #[LiveAction]
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    #[LiveAction]
    public function clearSearch(): void
    {
        $this->searchQuery = '';
    }

    /**
     * The outcome of the last test message, shown on its integration's card.
     *
     * @var array{id: string, ok: bool, detail: string}|null
     */
    #[LiveProp]
    public ?array $testResult = null;

    /**
     * Sends a message through one saved chat integration, now and not through
     * the queue, so the answer — delivered, or the provider's own refusal —
     * is on the page. Saving an integration proved nothing: on the test
     * instance a Telegram integration sat unused, and nothing said whether it
     * would work (29/09/2026).
     */
    #[LiveAction]
    public function sendTest(#[LiveArg] string $id): void
    {
        $setting = Ulid::isValid($id) ? $this->repository->findOneBy(['id' => Ulid::fromString($id), 'user' => $this->getUser()]) : null;

        if (! $setting instanceof TransportSetting || $this->getIntegrationType($setting->getTransport()) !== 'chatter') {
            return;
        }

        try {
            $this->transportFactory->forSetting($setting)->send(new ChatMessage($this->translator->trans('notification.marketplace.test.message')));
            $this->testResult = ['id' => $id, 'ok' => true, 'detail' => ''];
        } catch (Throwable $e) {
            // The provider's words: "chat not found", "Unauthorized" — they
            // say what to fix.
            $this->testResult = ['id' => $id, 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->selectedIntegration = '';
        $this->transport = '';
        $this->type = '';
    }
}
