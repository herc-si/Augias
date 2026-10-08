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

use Augias\InvoiceBundle\Email\InvoiceReminderEmail;
use Augias\InvoiceBundle\Email\ManualInvoiceReminderEmail;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @see \Augias\InvoiceBundle\Tests\Listener\Mailer\ReminderSubjectListenerTest
 */
class ReminderSubjectListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof InvoiceReminderEmail && ! $message instanceof ManualInvoiceReminderEmail) {
            return;
        }

        if (null !== $message->getSubject()) {
            return;
        }

        // In the language of the app: these were English for everyone
        // (08/10/2026).
        $key = $message instanceof InvoiceReminderEmail
            ? 'invoice.reminder_subject.' . $message->getReminderType()->value
            : 'invoice.reminder_subject.manual';

        $message->subject($this->translator->trans($key, ['%id%' => (string) $message->getInvoice()->getInvoiceId()], 'email'));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => '__invoke',
        ];
    }
}
