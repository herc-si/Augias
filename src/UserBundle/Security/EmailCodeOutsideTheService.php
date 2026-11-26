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

use Override;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use function method_exists;

/**
 * Where Augias is offered as a service, a code by email is no second factor:
 * a stolen mailbox holds both the password reset link and the code
 * (AuthenticatorAppPolicy). The authenticator app is required there, and the
 * email code was still offered in the settings — and, turned on, could be
 * picked at sign-in instead of the app. It is neither (Rémi, 26/11/2026).
 *
 * A self-hosted install keeps it.
 *
 * @see \Augias\UserBundle\Tests\Security\EmailCodeOutsideTheServiceTest
 */
#[AsDecorator('scheb_two_factor.security.email.provider')]
final readonly class EmailCodeOutsideTheService implements TwoFactorProviderInterface
{
    public function __construct(
        #[AutowireDecorated]
        private TwoFactorProviderInterface $inner,
        private AuthenticatorAppPolicy $policy,
    ) {
    }

    #[Override]
    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        return ! $this->policy->isRequired() && $this->inner->beginAuthentication($context);
    }

    /**
     * Not on the interface, but asked for when the provider has it.
     */
    public function needsPreparation(): bool
    {
        return ! method_exists($this->inner, 'needsPreparation') || (bool) $this->inner->needsPreparation();
    }

    #[Override]
    public function prepareAuthentication(object $user): void
    {
        $this->inner->prepareAuthentication($user);
    }

    #[Override]
    public function validateAuthenticationCode(object $user, string $authenticationCode): bool
    {
        return ! $this->policy->isRequired() && $this->inner->validateAuthenticationCode($user, $authenticationCode);
    }

    #[Override]
    public function getFormRenderer(): TwoFactorFormRendererInterface
    {
        return $this->inner->getFormRenderer();
    }
}
