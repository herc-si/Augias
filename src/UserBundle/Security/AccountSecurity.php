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

namespace Augias\UserBundle\Security;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What follows a change of the credentials that open an account — its
 * password, or the address its password is reset through. Someone who took
 * the mailbox, or a session, must not keep a way in once the holder has
 * taken the account back (Rémi, 22/11/2026):
 *
 * - devices trusted to skip the second factor are forgotten, so the next
 *   sign-in asks for it again everywhere;
 * - reset links still pending are void;
 * - the holder is told, at the address the account had until now — the
 *   one an intruder changing it would rather they did not read.
 *
 * Remember-me cookies need nothing here: their signature carries the
 * password hash, so a new password already voids them.
 *
 * @see \Augias\UserBundle\Tests\Security\AccountSecurityTest
 */
final readonly class AccountSecurity
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ResetPasswordRequestRepository $resetRequests,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function passwordChanged(User $user): void
    {
        $this->secure($user);
        $this->notify($user->getEmail(), 'password_changed', ['%email%' => $user->getEmail()]);
    }

    public function emailChanged(User $user, string $previousEmail): void
    {
        $this->secure($user);
        $this->notify($previousEmail, 'email_changed', ['%email%' => $previousEmail, '%new_email%' => $user->getEmail()]);
    }

    /**
     * Every device trusted to skip the second factor asks for it again on
     * its next sign-in: the trust cookies carry this version, now stale.
     * Also what the holder does from the two-factor page after losing a
     * computer, or ticking the box on a shared one (Rémi, 23/11/2026).
     */
    public function forgetTrustedDevices(User $user): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s u SET u.trustedVersion = u.trustedVersion + 1 WHERE u.id = :id', User::class))
            ->setParameter('id', $user->getId(), 'ulid')
            ->execute();
    }

    private function secure(User $user): void
    {
        $this->forgetTrustedDevices($user);
        $this->resetRequests->removeRequests($user);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function notify(string $to, string $what, array $parameters): void
    {
        $email = new TemplatedEmail()
            ->to($to)
            ->subject($this->translator->trans('email.account_notice.' . $what . '.subject', $parameters, 'email'))
            ->htmlTemplate('@AugiasUser/Email/account_notice.html.twig')
            ->context(['what' => $what, 'parameters' => $parameters]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // The change stands: a notice that cannot leave must not undo it.
            $this->logger->error('The account notice could not be sent.', ['exception' => $e, 'notice' => $what]);
        }
    }
}
