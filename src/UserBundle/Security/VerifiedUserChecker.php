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
use Override;
use SensitiveParameter;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Extends the global disabled-account check with an unverified-email block.
 *
 * Wired onto the stateless API and MCP firewalls so that, on hosted
 * (`saas_enabled`) deployments, an unverified user is hard-blocked from those
 * channels regardless of a valid token. On the web they sign in, and see only
 * the page asking them to verify, with a new link on offer, since 05/10/2026
 * ({@see \Augias\SaasBundle\EventSubscriber\UnverifiedEmailListener}).
 *
 * @see \Augias\UserBundle\Tests\Security\VerifiedUserCheckerTest
 */
final class VerifiedUserChecker extends UserChecker
{
    public function __construct(
        private readonly ToggleInterface $toggle,
    ) {
    }

    #[Override]
    public function checkPostAuth(UserInterface $user, #[SensitiveParameter] ?TokenInterface $token = null): void
    {
        parent::checkPostAuth($user, $token);

        if (! $this->toggle->isActive('saas_enabled')) {
            return;
        }

        if ($user instanceof User && ! $user->isVerified()) {
            throw new CustomUserMessageAccountStatusException('Please verify your email address before continuing.');
        }
    }
}
