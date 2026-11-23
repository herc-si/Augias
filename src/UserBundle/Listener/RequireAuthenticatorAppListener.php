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

namespace Augias\UserBundle\Listener;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\AuthenticatorAppPolicy;
use Augias\UserBundle\Security\RoutePermissionMap;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use function in_array;
use function str_starts_with;

/**
 * An account without an authenticator app, where one is required
 * ({@see AuthenticatorAppPolicy}), sets it up before anything else: every
 * page leads to the two-factor settings until it is done.
 *
 * After the firewall, and after the unverified-email gate: an address is
 * confirmed first, a company picked, then the app set up before the company
 * opens.
 *
 * @see \Augias\UserBundle\Tests\Functional\RequireAuthenticatorAppTest
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 0)]
final readonly class RequireAuthenticatorAppListener
{
    /**
     * Where the person was going when sent to set the app up: entering
     * their company, choosing a plan, activating electronic invoicing. The
     * settings page offers to carry on there once the app is set up, so
     * signing up does not stop at the second factor (Rémi, 23/11/2026).
     */
    public const string RESUME_SESSION_KEY = '_augias_after_authenticator_app';

    private const array OPEN_ROUTES = [
        '_2fa_list',
        '_logout',
        '_logout_main',
        '_login_main',
        '_login_check',
        '2fa_login',
        '2fa_login_check',
        '_solidworx_platform_security_two_factor_resend',
        '_verify_email',
        '_verify_email_resend',
        // The settings page lives inside a company: picking or creating one
        // comes first, or the two gates would send each other round.
        '_select_company',
        '_switch_company',
        '_create_company',
        '_onboarding',
        // The setup journey asks for the app itself, after who the company
        // is and before its e-invoicing platform (Rémi, 23/11/2026); the
        // steps after it cannot be reached before it is done (SetupJourney).
        '_onboarding_step',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AuthenticatorAppPolicy $policy,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (! $event->isMainRequest() || ! $this->policy->isRequired()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->attributes->get('_stateless') === true) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');

        if (in_array($route, self::OPEN_ROUTES, true) || str_starts_with($route, '_wdt') || str_starts_with($route, '_profiler')) {
            return;
        }

        // The settings page sets the app up through its live component.
        if (RoutePermissionMap::LIVE_COMPONENT_ROUTE === $route && 'User:TwoFactorSettings' === $request->attributes->get('_live_component')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        // Still signing in (a second factor pending), or an address not
        // confirmed yet: other gates come first.
        if ($token instanceof TwoFactorTokenInterface || ! $user instanceof User || ! $user->isVerified() || ! $this->policy->isMissingFor($user)) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface && ! $session->getFlashBag()->has('warning')) {
            $session->getFlashBag()->add('warning', 'security.two_factor.required.flash');
        }

        if ($session !== null && $request->isMethod('GET') && ! $request->isXmlHttpRequest()) {
            $session->set(self::RESUME_SESSION_KEY, $request->getRequestUri());
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('_2fa_list')));
    }
}
