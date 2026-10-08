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

namespace Augias\QuoteBundle\Listener\Mailer;

use Augias\QuoteBundle\Email\QuoteEmail;
use Augias\SettingsBundle\SystemConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

class QuoteSubjectListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfig $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        /** @var QuoteEmail $message */
        $message = $event->getMessage();

        if ($message instanceof QuoteEmail && null === $message->getSubject()) {
            $id = (string) $message->getQuote()->getQuoteId();

            // The company's own wording when it wrote one; otherwise the one
            // that goes with the language of the app. The stored default used
            // to be English for everyone (08/10/2026).
            $custom = (string) $this->config->get('quote/email_subject');

            $message->subject('' !== $custom
                ? \str_replace('{id}', $id, $custom)
                : $this->translator->trans('quote.subject', ['%id%' => $id, '%company%' => (string) $this->config->get('system/company/company_name')], 'email'));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => '__invoke',
        ];
    }
}
