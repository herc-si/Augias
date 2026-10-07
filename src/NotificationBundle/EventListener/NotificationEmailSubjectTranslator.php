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

namespace Augias\NotificationBundle\EventListener;

use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A notification's subject is a translation key, and nothing translated it on
 * the way to an e-mail — {@see NotificationOptionConfigurator} only sees chat
 * and text messages: a new client was announced as « client.create.subject ».
 *
 * Looked up among the e-mails' translations, then the application's.
 */
final readonly class NotificationEmailSubjectTranslator
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[AsEventListener(MessageEvent::class)]
    public function translate(MessageEvent $event): void
    {
        $email = $event->getMessage();

        if (! $email instanceof NotificationEmail) {
            return;
        }

        $subject = $email->getSubject();

        if (null === $subject || '' === $subject) {
            return;
        }

        foreach (['email', 'messages'] as $domain) {
            $translated = $this->translator->trans($subject, [], $domain);

            if ($translated !== $subject) {
                $email->subject($translated);

                return;
            }
        }
    }
}
