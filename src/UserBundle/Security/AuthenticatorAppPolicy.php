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

use Augias\AppMode;
use Augias\UserBundle\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where Augias is offered as a service, every account signs in with an
 * authenticator app: the books, the clients and the bank details of a
 * company are behind it (Rémi, 22/11/2026). A code by email does not count
 * — a stolen mailbox would hold both the reset link and the code.
 *
 * A self-hosted install leaves it to whoever runs it.
 *
 * @see \Augias\UserBundle\Tests\Security\AuthenticatorAppPolicyTest
 */
final readonly class AuthenticatorAppPolicy
{
    public function __construct(
        #[Autowire(param: 'app_mode')]
        private string $appMode,
    ) {
    }

    public function isRequired(): bool
    {
        return AppMode::SAAS->value === $this->appMode;
    }

    public function isMissingFor(User $user): bool
    {
        return $this->isRequired() && ! $user->isTotpAuthenticationEnabled();
    }
}
