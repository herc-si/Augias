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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Repository\UserRepository;
use Augias\UserBundle\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $id = $request->query->getString('id');

        try {
            $user = $this->findAcrossCompanies(Ulid::fromString($id));
        } catch (InvalidArgumentException) {
            return $this->invalid();
        }

        // A link that names an account no longer here: one deleted since the
        // mail went out. Said as such — "invalid link" had someone retry old
        // mails of accounts they had deleted (test instance, 29/09/2026).
        if (! $user instanceof User) {
            $this->logger->notice('Email verification link names an account that no longer exists');
            $this->addFlash('error', 'security.verify_email.flash.no_account');

            return $this->redirectToRoute('_login_main');
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

    /**
     * The link names its account by itself — id, signature and token — so the
     * company the browser has open does not come into it. Looked up under the
     * company filter, a link opened in a browser signed in to another account
     * found no one: the new account is not in that account's company (test
     * instance, 29/09/2026).
     */
    private function findAcrossCompanies(Ulid $id): ?User
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if ($wasEnabled) {
            $filters->disable('company');
        }

        $company = $this->companySelector->getCompany();

        try {
            return $this->userRepository->find($id);
        } finally {
            // Through the selector, not $filters->enable(): Doctrine re-enables
            // a filter without its parameters, and the company filter without
            // its company filters nothing for the rest of the request.
            if ($wasEnabled && $company instanceof Ulid) {
                $this->companySelector->switchCompany($company);
            }
        }
    }

    private function invalid(): Response
    {
        $this->logger->error('Email verification link has no readable account id');

        $this->addFlash('error', 'security.verify_email.flash.invalid');
        return $this->redirectToRoute('_login_main');
    }
}
