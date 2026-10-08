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

namespace Augias\CoreBundle\Listener;

use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Activity\DocumentEmail;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;

/**
 * Puts in a document's history the emails that carried it: when one left and
 * to which addresses, or that it could not be sent and why.
 *
 * On the mailer's own events, after the transport answered, rather than where
 * the email is built: "sent" here means the mail server took it, and the
 * addresses are the ones the listeners finally put on it.
 */
final readonly class DocumentEmailActivityListener
{
    public function __construct(
        private DocumentActivityRecorder $recorder,
    ) {
    }

    #[AsEventListener]
    public function onSent(SentMessageEvent $event): void
    {
        $email = $event->getMessage()->getOriginalMessage();

        if (! $email instanceof DocumentEmail || ! $email instanceof Email) {
            return;
        }

        $this->recorder->record(
            $email->activityDocument(),
            $email->activityCompany(),
            DocumentActivityType::Sent,
            $email->activityDetail(),
            $this->recipients($email),
        );
    }

    #[AsEventListener]
    public function onFailed(FailedMessageEvent $event): void
    {
        $email = $event->getMessage();

        if (! $email instanceof DocumentEmail || ! $email instanceof Email) {
            return;
        }

        $this->recorder->record(
            $email->activityDocument(),
            $email->activityCompany(),
            DocumentActivityType::SendFailed,
            $event->getError()->getMessage(),
            $this->recipients($email),
        );
    }

    /**
     * @return list<string>
     */
    private function recipients(Email $email): array
    {
        return array_values(array_unique(array_map(
            static fn (Address $address): string => $address->getAddress(),
            array_merge($email->getTo(), $email->getCc(), $email->getBcc()),
        )));
    }
}
