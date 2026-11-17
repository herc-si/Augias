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

use Augias\UserBundle\Security\RegistrationChallenge;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Hands the registration page's widget a fresh proof-of-work challenge.
 *
 * Public, like the page itself, and limited per address: a challenge costs
 * nothing to ask for, and a script asking for thousands of them is already
 * the abuse this guards against.
 */
final readonly class RegisterChallenge
{
    public function __construct(
        private RegistrationChallenge $challenge,
        #[Autowire(service: 'limiter.registration_challenge')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $response = new JsonResponse($this->challenge->create());
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
