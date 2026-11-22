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

namespace Augias\UserBundle\Action;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\UserSettingType;
use Augias\UserBundle\Form\Type\ProfileType;
use Augias\UserBundle\Repository\UserRepositoryInterface;
use Augias\UserBundle\Repository\UserSettingRepositoryInterface;
use Augias\UserBundle\Security\AccountSecurity;
use Augias\UserBundle\Security\EmailVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use function assert;
use function strcasecmp;

final readonly class EditProfile
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UserRepositoryInterface $userRepository,
        private UserSettingRepositoryInterface $userSettingRepository,
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
        private AccountSecurity $accountSecurity,
        private EmailVerifier $emailVerifier,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{form: FormView}|Response
     */
    #[Template('@AugiasUser/Profile/edit.html.twig')]
    public function __invoke(Request $request): array | Response
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (! $user instanceof User) {
            throw new AccessDeniedException('User must be authenticated to edit profile.');
        }

        $previousEmail = $user->getEmail();
        $form = $this->formFactory->create(ProfileType::class, $user);

        $form->get('locale')->setData($this->userSettingRepository->getSetting($user, UserSettingType::Locale)?->getValue());

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $emailChanged = strcasecmp($previousEmail, $user->getEmail()) !== 0;

            // A new address is the one password resets will go to: it is
            // confirmed before it counts, and the old one is told.
            if ($emailChanged) {
                $user->setVerified(false);
            }

            $this->userRepository->save($user);

            /** @var string|null $locale */
            $locale = $form->get('locale')->getData();
            $this->userSettingRepository->saveSetting($user, UserSettingType::Locale, $locale);

            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('success', 'profile.edit.success');

            if ($emailChanged) {
                $this->accountSecurity->emailChanged($user, $previousEmail);
                $this->sendConfirmation($user, $session);
            }
            // Seed the session locale immediately, so the redirect below already
            // renders in the new language instead of lagging by one request.
            $session->set('_locale', $locale ?? $request->getDefaultLocale());

            return new RedirectResponse($this->router->generate('_profile'));
        }

        return [
            'form' => $form->createView(),
        ];
    }

    private function sendConfirmation(User $user, Session $session): void
    {
        try {
            $this->emailVerifier->sendEmailConfirmation(
                '_verify_email',
                $user,
                new TemplatedEmail()
                    ->to($user->getEmail())
                    ->subject($this->translator->trans('email.confirmation.subject', [], 'email'))
                    ->htmlTemplate('@AugiasUser/Email/confirm_email.html.twig'),
            );
            $session->getFlashBag()->add('info', 'profile.edit.email_confirmation_sent');
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('The confirmation of a changed email could not be sent.', ['exception' => $e]);
            $session->getFlashBag()->add('error', 'email_verification.required.not_sent');
        }
    }
}
