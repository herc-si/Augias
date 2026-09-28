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

namespace Augias\SaasBundle\EventSubscriber;

use Augias\MailerBundle\Factory\MailerConfigFactory;
use Augias\SettingsBundle\SystemConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use function array_filter;
use function array_values;
use function str_ends_with;
use function strcasecmp;
use function strtolower;

/**
 * Sends from the hosted service's own address, with the company's in
 * Reply-To.
 *
 * EmailFromListener puts the company's address in From. Sent from the hosted
 * service's servers, that fails DMARC on the company's domain and lands in
 * spam, or is rejected. The recipient still sees the company's name, and a
 * reply still reaches the company.
 *
 * A company that set up its own sending service keeps its address: that
 * service is the one entitled to sign for its domain.
 *
 * @see \Augias\SaasBundle\Tests\EventSubscriber\PlatformSenderListenerTest
 */
final readonly class PlatformSenderListener implements EventSubscriberInterface
{
    public function __construct(
        private SystemConfig $config,
        #[Autowire(env: 'AUGIAS_SAAS_MAIL_FROM')]
        private string $platformAddress,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof Email || '' === $this->platformAddress) {
            return;
        }

        if (null !== $this->config->get(MailerConfigFactory::CONFIG_KEY)) {
            return;
        }

        $from = $message->getFrom();

        // Nobody reads replies sent to the platform itself, nor to the
        // placeholder a company gets until it sets its own (RFC 2606 domain).
        $replyTo = array_values(array_filter(
            $from,
            fn (Address $address): bool => 0 !== strcasecmp($address->getAddress(), $this->platformAddress)
                && ! str_ends_with(strtolower($address->getAddress()), '.example'),
        ));

        if ([] !== $replyTo && [] === $message->getReplyTo()) {
            $message->replyTo(...$replyTo);
        }

        $message->from(new Address($this->platformAddress, ($from[0] ?? null)?->getName() ?? ''));
        $event->getEnvelope()->setSender(new Address($this->platformAddress));
    }

    public static function getSubscribedEvents(): array
    {
        // After EmailFromListener (-256), which sets the company's address.
        return [
            MessageEvent::class => ['__invoke', -512],
        ];
    }
}
