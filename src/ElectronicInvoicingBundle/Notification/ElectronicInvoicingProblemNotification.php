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

use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoicingProblem;
use Augias\NotificationBundle\Attribute\AsNotification;
use Augias\NotificationBundle\Enum\NotificationCategory;
use Augias\NotificationBundle\Notification\NotificationMessage;
use Override;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Twig\Environment;

/**
 * Whatever goes wrong with electronic invoicing and needs someone to act:
 * an invoice that did not leave, one suspended by the client's platform, an
 * account no longer verified, a connection lost, a report not made.
 *
 * Parameters: `company` (always — it is what keeps the notification within
 * it), `problem` ({@see ElectronicInvoicingProblem}), and when there is one,
 * `invoice` and `detail` (the platform's own words).
 */
#[AsNotification(
    name: self::EVENT,
    title: 'Electronic Invoicing Problem',
    description: 'When an invoice cannot be sent electronically, is suspended, or the platform account needs attention',
    icon: 'tabler:building-broadcast-tower',
    category: NotificationCategory::INVOICE,
    defaultOn: true,
)]
class ElectronicInvoicingProblemNotification extends NotificationMessage
{
    public const EVENT = 'electronic_invoicing_problem';

    final public const string HTML_TEMPLATE = '@AugiasElectronicInvoicing/Email/notification_problem.html.twig';

    final public const string TEXT_TEMPLATE = '@AugiasElectronicInvoicing/Email/notification_problem.text.twig';

    public function getTextContent(Environment $twig): string
    {
        return $twig->render(self::TEXT_TEMPLATE, $this->context());
    }

    #[Override]
    public function getSubject(): string
    {
        return $this->problem()->translationKey('heading');
    }

    #[Override]
    public function asEmailMessage(EmailRecipientInterface $recipient, ?string $transport = null): EmailMessage
    {
        $message = parent::asEmailMessage($recipient, $transport);

        $email = $message->getMessage();

        if ($email instanceof NotificationEmail) {
            $email->textTemplate(self::TEXT_TEMPLATE);
            $email->htmlTemplate(self::HTML_TEMPLATE);
            $email->context($this->context());
            $email->importance(NotificationEmail::IMPORTANCE_HIGH);
        }

        return $message;
    }

    private function problem(): ElectronicInvoicingProblem
    {
        $problem = $this->getParameters()['problem'] ?? null;

        return $problem instanceof ElectronicInvoicingProblem ? $problem : ElectronicInvoicingProblem::SendFailed;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'invoice' => null,
            'detail' => null,
            ...$this->getParameters(),
            'problem' => $this->problem(),
        ];
    }
}
