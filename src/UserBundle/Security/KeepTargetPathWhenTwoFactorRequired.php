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

use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Where the second factor is asked, the page someone was going to when they
 * were sent to sign in is kept: the bundle's own handler replaced it with
 * the page the sign-in lands on — the company picker — so a support link,
 * a deep link or an OAuth authorisation was lost behind the second factor
 * (22/11/2026, once the authenticator app became required on the service).
 *
 * Without such a page, the one being asked for is kept, as before.
 *
 * @see \Augias\SaasBundle\Tests\Functional\SupportAccessTest::testSigningInOnTheWayLeadsIntoTheCompany
 */
final readonly class KeepTargetPathWhenTwoFactorRequired implements AuthenticationRequiredHandlerInterface
{
    use TargetPathTrait;

    private const string FIREWALL = 'main';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        if ($request->hasSession() && $request->isMethodSafe() && ! $request->isXmlHttpRequest()) {
            $session = $request->getSession();

            if (null === $this->getTargetPath($session, self::FIREWALL)) {
                $this->saveTargetPath($session, self::FIREWALL, $request->getUri());
            }
        }

        return new RedirectResponse($this->urlGenerator->generate('2fa_login'));
    }
}
