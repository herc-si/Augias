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

namespace Augias\UserBundle\Tests\Security;

use Augias\UserBundle\Security\AuthenticatorAppPolicy;
use Augias\UserBundle\Security\EmailCodeOutsideTheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use stdClass;

#[CoversClass(EmailCodeOutsideTheService::class)]
final class EmailCodeOutsideTheServiceTest extends TestCase
{
    /**
     * A mailbox stolen holds the reset link and the code: on the service,
     * the email code neither starts nor passes (Rémi, 26/11/2026).
     */
    public function testOnTheServiceTheEmailCodeIsNoSecondFactor(): void
    {
        $provider = $this->provider('saas');

        self::assertFalse($provider->beginAuthentication($this->createStub(AuthenticationContextInterface::class)));
        self::assertFalse($provider->validateAuthenticationCode(new stdClass(), '123456'));
    }

    public function testASelfHostedInstallKeepsIt(): void
    {
        $provider = $this->provider('self-hosted');

        self::assertTrue($provider->beginAuthentication($this->createStub(AuthenticationContextInterface::class)));
        self::assertTrue($provider->validateAuthenticationCode(new stdClass(), '123456'));
    }

    private function provider(string $appMode): EmailCodeOutsideTheService
    {
        $inner = $this->createStub(TwoFactorProviderInterface::class);
        $inner->method('beginAuthentication')->willReturn(true);
        $inner->method('validateAuthenticationCode')->willReturn(true);

        return new EmailCodeOutsideTheService($inner, new AuthenticatorAppPolicy($appMode));
    }
}
