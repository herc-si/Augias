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

namespace Augias\NotificationBundle\Notification;

use Augias\CoreBundle\Traits\FlashErrorTrait;
use Augias\NotificationBundle\Attribute\AsNotification;
use Augias\NotificationBundle\Configurator\ConfiguratorInterface;
use Augias\NotificationBundle\Exception\InvalidNotificationMessageException;
use Augias\NotificationBundle\Repository\UserNotificationRepository;
use Psr\Log\LoggerInterface;
use ReflectionObject;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

class NotificationManager
{
    use FlashErrorTrait;

    /**
     * @param ServiceLocator<ConfiguratorInterface> $transportConfigurations
     */
    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly UserNotificationRepository $userNotificationRepository,
        #[AutowireLocator(ConfiguratorInterface::DI_TAG)]
        private readonly ServiceLocator $transportConfigurations,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function sendNotification(NotificationMessage $message): void
    {
        $attributes = new ReflectionObject($message)
            ->getAttributes(AsNotification::class);

        if ($attributes === []) {
            throw new InvalidNotificationMessageException(sprintf(
                'The notification message "%s" must have the %s set.',
                $message::class,
                AsNotification::class,
            ));
        }

        $event = $attributes[0]->getArguments()['name'] ?? null;

        $userNotifications = $this->userNotificationRepository->findBy(['event' => $event]);
        $hasTransportFailure = false;

        foreach ($userNotifications as $userNotification) {
            $channels = [];

            if ($userNotification->isEmail()) {
                $channels[] = 'email';
            }

            foreach ($userNotification->getTransports() as $transport) {
                $transportConfiguration = $this->transportConfigurations->get($transport->getTransport());
                assert($transportConfiguration instanceof ConfiguratorInterface);

                $channels[] = sprintf(
                    '%s/%s',
                    match ($transportConfiguration::getType()) {
                        'texter' => 'sms',
                        'chatter' => 'chat',
                        default => $transportConfiguration::getType(),
                    },
                    $transport->getId()
                        ->toString(),
                );
            }

            $message->channels($channels);

            try {
                $this->notifier->send($message, new Recipient($userNotification->getUser()->getEmail(), (string) $userNotification->getUser()->getMobile()));
            } catch (TransportExceptionInterface | HandlerFailedException $e) {
                // HandlerFailedException: Messenger's wrapping of the same failure,
                // since chat and SMS messages are handled in the request. Caught
                // bare, a chat service's refusal ("chat not found") made creating
                // a client a 500 (test instance, 29/09/2026). A notification must
                // not fail the action that sent it.
                //
                // Not moved to the worker instead: the message carries the
                // notification, entities included, which NotificationOptionConfigurator
                // renders at send time — serialized, they would reach the worker
                // detached.
                $this->logger->error('Failed to send notification: ' . $e->getMessage(), [
                    'exception' => $e,
                    'event' => $event,
                ]);

                $hasTransportFailure = true;
            }
        }

        if ($hasTransportFailure) {
            $this->addFlashError('notification.send_failed');
        }
    }
}
