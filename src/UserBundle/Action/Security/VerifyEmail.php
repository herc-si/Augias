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
use Augias\UserBundle\Repository\UserRepository;
use Augias\UserBundle\Security\EmailVerifier;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\ExpiredSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

final class VerifyEmail extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $userRepository,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $id = $request->query->getString('id');

        try {
            $user = $this->userRepository->find(Ulid::fromString($id));
        } catch (InvalidArgumentException) {
            return $this->invalid();
        }

        // Ensure the user exists in persistence
        if (! $user instanceof User) {
            return $this->invalid();
        }

        // validate the email confirmation link, sets User::isVerified=true and persists
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            // Production writes its log only once something reaches `error`, so
            // a refused link left no trace at all (seen 29/09/2026, not
            // reproduced since). An expired link is ordinary; any other refusal
            // is not, and is logged loud enough to be kept, with the request
            // around it. The reason, never the link: it carries a token.
            if (! $exception instanceof ExpiredSignatureException) {
                $this->logger->error('Email verification link refused', ['reason' => $exception::class]);
            }

            $this->addFlash('error', $this->translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('_login_main');
        }

        $this->addFlash('success', 'security.verify_email.flash.success');

        return $this->security->login($user, 'security.authenticator.form_login.main', 'main');
    }

    private function invalid(): Response
    {
        $this->logger->error('Email verification link names no account');

        $this->addFlash('error', 'security.verify_email.flash.invalid');
        return $this->redirectToRoute('_login_main');
    }
}
