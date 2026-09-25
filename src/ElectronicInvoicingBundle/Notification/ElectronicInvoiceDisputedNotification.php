<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Notification;

use Augias\NotificationBundle\Attribute\AsNotification;
use Augias\NotificationBundle\Enum\NotificationCategory;
use Augias\NotificationBundle\Notification\NotificationMessage;
use Override;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Twig\Environment;

/**
 * Fired by PollSuperPdpInvoiceStatusCommand when a client disputes an invoice
 * sent electronically (fr:207) — with their reason and their own words, so
 * the company knows what to settle before the invoice can be accepted.
 */
#[AsNotification(
    name: self::EVENT,
    title: 'Electronic Invoice Disputed',
    description: 'When a client disputes an invoice sent electronically',
    icon: 'tabler:building-broadcast-tower',
    category: NotificationCategory::INVOICE,
)]
class ElectronicInvoiceDisputedNotification extends NotificationMessage
{
    public const EVENT = 'electronic_invoice_disputed';

    final public const string HTML_TEMPLATE = '@AugiasElectronicInvoicing/Email/notification_disputed.html.twig';

    final public const string TEXT_TEMPLATE = '@AugiasElectronicInvoicing/Email/notification_disputed.text.twig';

    public function getTextContent(Environment $twig): string
    {
        return $twig->render(self::TEXT_TEMPLATE, $this->getParameters());
    }

    #[Override]
    public function getSubject(): string
    {
        return 'Electronic Invoice Disputed';
    }

    #[Override]
    public function asEmailMessage(EmailRecipientInterface $recipient, ?string $transport = null): EmailMessage
    {
        $message = parent::asEmailMessage($recipient, $transport);

        $email = $message->getMessage();

        if ($email instanceof NotificationEmail) {
            $email->textTemplate(self::TEXT_TEMPLATE);
            $email->htmlTemplate(self::HTML_TEMPLATE);
            $email->context($this->getParameters());
            $email->importance(NotificationEmail::IMPORTANCE_HIGH);
        }

        return $message;
    }
}
