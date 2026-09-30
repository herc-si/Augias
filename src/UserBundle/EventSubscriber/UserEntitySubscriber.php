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

namespace Augias\UserBundle\EventSubscriber;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\EmailVerifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @see \Augias\UserBundle\Tests\EventSubscriber\UserEntitySubscriberTest
 */
#[AsEntityListener(event: Events::postPersist, entity: User::class)]
final readonly class UserEntitySubscriber
{
    public function __construct(
        private EmailVerifier $emailVerifier,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    public function postPersist(User $user): void
    {
        if ($user->isVerified()) {
            return;
        }

        try {
            $this->emailVerifier->sendEmailConfirmation(
                '_verify_email',
                $user,
                new TemplatedEmail()
                    ->to($user->getEmail())
                    ->subject($this->translator->trans('email.confirmation.subject', [], 'email'))
                    ->htmlTemplate('@AugiasUser/Email/confirm_email.html.twig')
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send email confirmation', [
                'exception' => $e,
            ]);
        }
    }
}
