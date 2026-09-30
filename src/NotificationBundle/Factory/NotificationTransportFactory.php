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

namespace Augias\NotificationBundle\Factory;

use Augias\NotificationBundle\Configurator\ConfiguratorInterface;
use Augias\NotificationBundle\Entity\TransportSetting;
use Augias\NotificationBundle\Notification\Transports;
use Augias\NotificationBundle\Repository\TransportSettingRepository;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Notifier\Exception\ExceptionInterface as NotifierExceptionInterface;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NotificationTransportFactory
{
    /**
     * @param ServiceLocator<ConfiguratorInterface> $transportConfigurations
     */
    public function __construct(
        private Transport $transport,
        private TransportSettingRepository $transportSettingRepository,
        #[AutowireLocator(ConfiguratorInterface::DI_TAG)]
        private ServiceLocator $transportConfigurations,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public static function fromDsn(#[SensitiveParameter] string $dsn, ?EventDispatcherInterface $dispatcher = null, ?HttpClientInterface $client = null): TransportInterface
    {
        return Transport::fromDsn($dsn, $dispatcher, $client);
    }

    /**
     * @param array<string> $dsns
     */
    public static function fromDsns(#[SensitiveParameter] array $dsns, ?EventDispatcherInterface $dispatcher = null, ?HttpClientInterface $client = null): TransportInterface
    {
        return Transport::fromDsns($dsns, $dispatcher, $client);
    }

    /**
     * @param array<string> $dsns
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function fromStrings(#[SensitiveParameter] array $dsns): Transports
    {
        $transports = [];

        foreach ($this->transportSettingRepository->findAll() as $setting) {
            $configurator = $this->transportConfigurations->get($setting->getTransport());
            assert($configurator instanceof ConfiguratorInterface);

            try {
                $transports[$setting->getId()->toString()] = $this->transport->fromDsnObject($configurator->configure($setting->getSettings()));
            } catch (UnsupportedSchemeException) {
                continue;
            } catch (NotifierExceptionInterface $e) {
                // One integration set up wrong must not take the rest with it:
                // every notification builds every transport, so a malformed
                // Telegram token made creating a client a 500 (test instance,
                // 29/09/2026). Left out, logged; its Test button says why.
                $this->logger?->error('Notification integration left out: it cannot be built', [
                    'integration' => $setting->getId()->toString(),
                    'transport' => $setting->getTransport(),
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return new Transports($transports);
    }

    /**
     * The transport of one saved integration, on its own: what "send a test
     * message" goes through, so a wrong token or chat id is told at once.
     */
    public function forSetting(TransportSetting $setting): TransportInterface
    {
        $configurator = $this->transportConfigurations->get($setting->getTransport());
        assert($configurator instanceof ConfiguratorInterface);

        return $this->transport->fromDsnObject($configurator->configure($setting->getSettings()));
    }

    public function fromString(#[SensitiveParameter] string $dsn): TransportInterface
    {
        return self::fromDsns([$dsn]);
    }

    public function fromDsnObject(Dsn $dsn): TransportInterface
    {
        return $this->transport->fromDsnObject($dsn);
    }
}
