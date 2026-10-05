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

namespace Augias\SaasBundle\EventSubscriber;

use Augias\UserBundle\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use function in_array;
use function str_starts_with;

/**
 * On the hosted service, nothing works until the email address is verified:
 * no company, no plan, no purchase (05/10/2026: an unverified account bought
 * a plan). The page says so and offers a new link; signing out stays open.
 *
 * Before the subscription checks ({@see RequestListener}) and after the
 * firewall, which has put the user in place by then.
 *
 * @see \Augias\SaasBundle\Tests\Functional\UnverifiedEmailTest
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 1)]
final readonly class UnverifiedEmailListener
{
    private const array OPEN_ROUTES = [
        '_verify_email',
        '_verify_email_resend',
        '_logout',
        '_logout_main',
        '_login_main',
        '_login_check',
        '2fa_login',
        '2fa_login_check',
    ];

    public function __construct(
        private Security $security,
        private Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
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

        $user = $this->security->getUser();
        if (! $user instanceof User || $user->isVerified()) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('@AugiasSaas/subscription/verify_email.html.twig', ['email' => $user->getEmail()]),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
