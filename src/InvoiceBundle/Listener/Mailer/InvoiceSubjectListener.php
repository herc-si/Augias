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
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\SettingsBundle\SystemConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

class InvoiceSubjectListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfig $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if ($message instanceof InvoiceEmail && null === $message->getSubject()) {
            $id = (string) $message->getInvoice()->getInvoiceId();

            // The company's own wording when it wrote one, in its language;
            // otherwise the one that goes with the language of the app. The
            // stored default used to be English for everyone (08/10/2026).
            $custom = (string) $this->config->get('invoice/email_subject');

            $message->subject('' !== $custom
                ? \str_replace('{id}', $id, $custom)
                : $this->translator->trans('invoice.subject', ['%id%' => $id, '%company%' => $this->companyName()], 'email'));
        }

        // A credit note had no subject at all.
        if ($message instanceof CreditNoteEmail && null === $message->getSubject()) {
            $message->subject($this->translator->trans('credit_note.subject', ['%id%' => $message->getCreditNote()->getCreditNoteId(), '%company%' => $this->companyName()], 'email'));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => '__invoke',
        ];
    }

    private function companyName(): string
    {
        return (string) $this->config->get('system/company/company_name');
    }
}
