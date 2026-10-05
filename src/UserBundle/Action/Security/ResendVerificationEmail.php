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

namespace Augias\UserBundle\Action\Security;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\EmailVerifier;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the signed-in user a new link to verify their email address: the
 * first one lost, expired, or sent to a mailbox they could not reach then.
 * Once a minute at most, so the button cannot be used to flood a mailbox.
 */
final class ResendVerificationEmail extends AbstractController
{
    private const string SENT_AT = 'verify_email_resent_at';

    private const int WAIT_SECONDS = 60;

    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();

        if (! $user instanceof User || $user->isVerified()) {
            return $this->redirectToRoute('_dashboard');
        }

        if (! $this->isCsrfTokenValid('verify_email_resend', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'email_verification.required.invalid_token');

            return $this->redirectToRoute('_dashboard');
        }

        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $sentAt = $session->get(self::SENT_AT);

        if (is_int($sentAt) && $now - $sentAt < self::WAIT_SECONDS) {
            $this->addFlash('warning', 'email_verification.required.wait');

            return $this->redirectToRoute('_dashboard');
        }

        try {
            $this->emailVerifier->sendEmailConfirmation(
                '_verify_email',
                $user,
                new TemplatedEmail()
                    ->to($user->getEmail())
                    ->subject($this->translator->trans('email.confirmation.subject', [], 'email'))
                    ->htmlTemplate('@AugiasUser/Email/confirm_email.html.twig'),
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to resend email confirmation', ['exception' => $e]);
            $this->addFlash('error', 'email_verification.required.not_sent');

            return $this->redirectToRoute('_dashboard');
        }

        $session->set(self::SENT_AT, $now);
        $this->addFlash('success', 'email_verification.required.sent');

        return $this->redirectToRoute('_dashboard');
    }
}
