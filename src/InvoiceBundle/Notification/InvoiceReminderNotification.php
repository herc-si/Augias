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

namespace Augias\InvoiceBundle\Notification;

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\NotificationBundle\Attribute\AsNotification;
use Augias\NotificationBundle\Enum\NotificationCategory;
use Augias\NotificationBundle\Notification\NotificationMessage;
use BackedEnum;
use Override;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Twig\Environment;

/**
 * @see \Augias\InvoiceBundle\Tests\Notification\InvoiceReminderNotificationTest
 */
#[AsNotification(
    name: self::EVENT,
    title: 'Invoice Payment Reminder',
    description: 'Reminder sent to clients about upcoming or overdue invoices',
    icon: 'tabler:clock',
    category: NotificationCategory::INVOICE,
)]
class InvoiceReminderNotification extends NotificationMessage
{
    public const EVENT = 'invoice_reminder';

    final public const string HTML_TEMPLATE = '@AugiasInvoice/Email/reminder.html.twig';

    final public const string TEXT_TEMPLATE = '@AugiasInvoice/Email/reminder.text.twig';

    /**
     * Returns parameters with 'reminder_type' normalized to string value.
     * Converts BackedEnum instances to their string values for Twig templates and email context.
     *
     * @return array<string, mixed>
     */
    private function getNormalizedParameters(): array
    {
        $parameters = $this->getParameters();

        // Convert reminder_type enum to string value if needed
        if (isset($parameters['reminder_type']) && $parameters['reminder_type'] instanceof BackedEnum) {
            $parameters['reminder_type'] = $parameters['reminder_type']->value;
        }

        return $parameters;
    }

    public function getTextContent(Environment $twig): string
    {
        return $twig->render(self::TEXT_TEMPLATE, $this->getNormalizedParameters());
    }

    #[Override]
    public function getSubject(): string
    {
        $parameters = $this->getNormalizedParameters();
        $reminderType = $parameters['reminder_type'] ?? '';
        $invoiceId = $parameters['invoice']?->getInvoiceId() ?? '';

        // A key, translated with the invoice's number on the way out
        // (NotificationEmailSubjectTranslator): the subject used to be
        // English whatever the language of the app (08/10/2026).
        return match ($reminderType) {
            'pre_due', 'overdue_1', 'overdue_7', 'overdue_14' => 'invoice.reminder_subject.' . $reminderType,
            default => 'invoice.reminder_subject.manual',
        };
    }

    #[Override]
    public function getSubjectParameters(): array
    {
        $invoice = $this->getParameters()['invoice'] ?? null;

        return ['%id%' => $invoice instanceof Invoice ? (string) $invoice->getInvoiceId() : ''];
    }

    #[Override]
    public function asEmailMessage(EmailRecipientInterface $recipient, ?string $transport = null): EmailMessage
    {
        $message = parent::asEmailMessage($recipient, $transport);

        $email = $message->getMessage();

        if ($email instanceof NotificationEmail) {
            $normalizedParameters = $this->getNormalizedParameters();

            $email->textTemplate(self::TEXT_TEMPLATE);
            $email->htmlTemplate(self::HTML_TEMPLATE);
            $email->context($normalizedParameters);

            $reminderType = $normalizedParameters['reminder_type'] ?? '';
            $importance = $reminderType == 'overdue_14'
                ? NotificationEmail::IMPORTANCE_URGENT
                : NotificationEmail::IMPORTANCE_MEDIUM;
            $email->importance($importance);
        }

        return $message;
    }
}
