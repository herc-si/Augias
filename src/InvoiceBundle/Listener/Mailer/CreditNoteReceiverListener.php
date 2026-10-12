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

namespace Augias\InvoiceBundle\Listener\Mailer;

use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Augias\SettingsBundle\SystemConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

/**
 * Addresses a credit note to the contacts chosen on it, like an invoice. The
 * invoice copy address gets the credit note too: it answers to an invoice.
 *
 * @see \Augias\InvoiceBundle\Tests\Listener\Mailer\CreditNoteReceiverListenerTest
 */
final readonly class CreditNoteReceiverListener implements EventSubscriberInterface
{
    public function __construct(
        private SystemConfig $config,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof CreditNoteEmail || [] !== $message->getTo()) {
            return;
        }

        foreach ($message->getCreditNote()->getUsers() as $user) {
            $message->addTo(new Address((string) $user->getEmail(), trim(sprintf('%s %s', $user->getFirstName(), $user->getLastName()))));
        }

        if ('' !== ($bcc = (string) $this->config->get('invoice/bcc_address'))) {
            $message->addBcc($bcc);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => '__invoke',
        ];
    }
}
