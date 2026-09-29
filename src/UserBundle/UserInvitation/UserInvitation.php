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

namespace Augias\UserBundle\UserInvitation;

use Augias\UserBundle\Entity\UserInvitation as UserInvitationEntity;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class UserInvitation
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function sendUserInvitation(UserInvitationEntity $invitation): void
    {
        $mail = new TemplatedEmail();

        $mail->to($invitation->getEmail())
            ->from($invitation->getInvitedBy()?->getEmail())
            ->subject($this->translator->trans('invitation.subject', ['%company%' => $invitation->getCompany()->getName()], 'email'))
            ->htmlTemplate('@AugiasUser/Email/invitation.html.twig')
            ->context([
                'invitation' => $invitation,
            ]);

        $this->mailer->send($mail);
    }

    public function sendExpiryReminder(UserInvitationEntity $invitation): void
    {
        $mail = new TemplatedEmail();

        $mail->to($invitation->getEmail())
            ->from($invitation->getInvitedBy()?->getEmail())
            ->subject($this->translator->trans('invitation.reminder.subject', ['%company%' => $invitation->getCompany()->getName()], 'email'))
            ->htmlTemplate('@AugiasUser/Email/invitation_reminder.html.twig')
            ->context([
                'invitation' => $invitation,
            ]);

        $this->mailer->send($mail);
    }
}
