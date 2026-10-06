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

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Traits\FlashErrorTrait;
use Augias\NotificationBundle\Attribute\AsNotification;
use Augias\NotificationBundle\Configurator\ConfiguratorInterface;
use Augias\NotificationBundle\Entity\TransportSetting;
use Augias\NotificationBundle\Exception\InvalidNotificationMessageException;
use Augias\NotificationBundle\Repository\UserNotificationRepository;
use Psr\Log\LoggerInterface;
use ReflectionObject;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly ?TranslatorInterface $translator = null,
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

        $userNotifications = $this->userNotificationRepository->findSubscribers($event, $this->companyOf($message));

        if ($userNotifications === null) {
            $this->logger->error('Notification not sent: no company to send it within', ['event' => $event]);

            return;
        }

        /** @var array<string, true> $failures what to tell the user, once each */
        $failures = [];

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

                $failures[$this->describeFailure($e, $userNotification->getTransports())] = true;
            }
        }

        foreach (array_keys($failures) as $failure) {
            $this->addFlashError($failure);
        }
    }

    /**
     * The company the notification is about, read from what it carries — an
     * invoice, a client, a payment, an alert. Tasks run from cron have no
     * company of their own: this is what keeps their notifications within one.
     */
    private function companyOf(NotificationMessage $message): ?Company
    {
        foreach ($message->getParameters() as $parameter) {
            if ($parameter instanceof Company) {
                return $parameter;
            }

            if (is_object($parameter) && method_exists($parameter, 'getCompany')) {
                $company = $parameter->getCompany();

                if ($company instanceof Company) {
                    return $company;
                }
            }
        }

        return null;
    }

    /**
     * What failed, said in terms of what the user set up. The message used to
     * be "check your email settings" whatever failed — on the test instance it
     * was a Telegram integration whose chat could not be found (29/09/2026).
     *
     * @param iterable<TransportSetting> $settings the integrations this notification went to
     */
    private function describeFailure(TransportExceptionInterface | HandlerFailedException $e, iterable $settings): string
    {
        $message = $e instanceof HandlerFailedException ? $e->getEnvelope()->getMessage() : null;

        if (! $message instanceof ChatMessage && ! $message instanceof SmsMessage) {
            return $this->trans('notification.send_failed');
        }

        // The provider's own words, e.g. "Bad Request: chat not found".
        $reason = ($e->getWrappedExceptions()[0] ?? $e)->getMessage();

        foreach ($settings as $setting) {
            if ($setting->getId()?->toString() === $message->getTransport()) {
                return $this->trans('notification.send_failed_integration', [
                    '%name%' => $setting->getName(),
                    '%transport%' => $setting->getTransport(),
                    '%reason%' => $reason,
                ]);
            }
        }

        return $this->trans('notification.send_failed_channel', ['%reason%' => $reason]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters) ?? $key;
    }
}
