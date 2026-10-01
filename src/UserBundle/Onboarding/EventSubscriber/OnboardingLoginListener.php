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

namespace Augias\UserBundle\Onboarding\EventSubscriber;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Onboarding\Manager\OnboardingManager;
use Augias\UserBundle\Security\SupportAccess;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use function count;

/**
 * Redirect users to onboarding after login if not completed
 *
 * IMPORTANT: Users invited to join an existing company should NOT go through onboarding.
 * Those users already have a company assigned during registration.
 *
 * This listener runs only once per login, avoiding the performance overhead of checking
 * onboarding status on every request.
 */
final readonly class OnboardingLoginListener implements EventSubscriberInterface
{
    public function __construct(
        private OnboardingManager $onboardingManager,
        private RouterInterface $router,
        private SupportAccess $supportAccess,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => ['onLoginSuccess', -10], // Run after default handlers
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        // Only handle User entities
        if (! $user instanceof User) {
            return;
        }

        // Check if user was invited (has a company already)
        // Invited users skip onboarding entirely
        if (count($user->getCompanies()) > 0) {
            // User has a company - they were either invited or completed onboarding
            // Make sure onboarding is marked complete if not already
            if (! $this->onboardingManager->isOnboardingComplete($user)) {
                $this->onboardingManager->dismissOnboarding($user);
            }

            return;
        }

        // Someone let into a company to help it is on their way there, not
        // setting up a business of their own.
        if ($this->supportAccess->hasAnyPass($user)) {
            return;
        }

        // Check if onboarding is complete
        if (! $this->onboardingManager->isOnboardingComplete($user)) {
            // User needs to complete onboarding - redirect them
            $url = $this->router->generate('_onboarding');
            $response = new RedirectResponse($url);
            $event->setResponse($response);
        }
    }
}
