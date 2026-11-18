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
 * The platform has verified the company after making it wait: electronic
 * invoicing runs from now on. The end of the setup's last step, which can
 * take longer than anyone stays on the page.
 *
 * Parameters: `company`.
 */
#[AsNotification(
    name: self::EVENT,
    title: 'Electronic Invoicing Account Verified',
    description: 'When the electronic invoicing platform has verified your company and invoices can go through it',
    icon: 'tabler:circle-check',
    category: NotificationCategory::INVOICE,
    defaultOn: true,
)]
class ElectronicInvoicingAccountVerifiedNotification extends NotificationMessage
{
    public const EVENT = 'electronic_invoicing_account_verified';

    final public const string HTML_TEMPLATE = '@AugiasElectronicInvoicing/Email/notification_verified.html.twig';

    final public const string TEXT_TEMPLATE = '@AugiasElectronicInvoicing/Email/notification_verified.text.twig';

    public function getTextContent(Environment $twig): string
    {
        return $twig->render(self::TEXT_TEMPLATE, $this->getParameters());
    }

    #[Override]
    public function getSubject(): string
    {
        return 'einvoicing.notification_verified.heading';
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
        }

        return $message;
    }
}
