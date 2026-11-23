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

namespace Augias\UserBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Listener\RequireAuthenticatorAppListener;
use Augias\UserBundle\Test\Factory\UserFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * On the service, every account sets up an authenticator app before it
 * opens anything (Rémi, 22/11/2026): a code by email does not count.
 */
#[CoversClass(RequireAuthenticatorAppListener::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class RequireAuthenticatorAppTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testEveryPageLeadsToTheSetUpUntilTheAppIsThere(): void
    {
        $browser = $this->browser()->actingAs($this->user(totp: false))->interceptRedirects();

        foreach (['/dashboard', '/clients', '/billing/'] as $page) {
            $browser->visit($page)->assertRedirectedTo('/profile/2fa');
        }

        $browser
            ->visit('/profile/2fa')
            ->assertSuccessful()
            ->assertSee('Authenticator app required');
    }

    /**
     * Signing up used to stop at the second factor: once the app was set up,
     * nothing led back to entering the company or electronic invoicing
     * (Rémi, 23/11/2026).
     */
    public function testOnceTheAppIsSetUpThePageOffersToCarryOnWhereThePersonWasGoing(): void
    {
        $user = $this->user(totp: false);
        $browser = $this->browser()->actingAs($user)->interceptRedirects();

        $browser->visit('/electronic-invoicing/setup')->assertRedirectedTo('/profile/2fa');
        $browser->visit('/profile/2fa')->assertNotSee('Pick up where you were');

        // Set up meanwhile, as the settings page does; the session — and
        // where the person was going — stays.
        $this->em->getConnection()->executeStatement('UPDATE users SET totp_secret = ? WHERE email = ?', ['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'jo@totp.test']);

        $browser
            ->visit('/profile/2fa')
            ->assertSuccessful()
            ->assertSee('Pick up where you were')
            ->assertSee('Carry on');
    }

    public function testACodeByEmailIsNotEnough(): void
    {
        $user = $this->user(totp: false);
        $user->enableEmailAuth(true);
        $this->em->flush();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->visit('/dashboard')
            ->assertRedirectedTo('/profile/2fa');
    }

    public function testAnAccountWithTheAppGoesThrough(): void
    {
        $this->browser()
            ->actingAs($this->user(totp: true))
            ->visit('/dashboard')
            ->assertSuccessful()
            ->assertNotSee('Authenticator app required');
    }

    public function testAnUnconfirmedAddressIsConfirmedFirst(): void
    {
        $this->browser()
            ->actingAs($this->user(totp: false, verified: false))
            ->visit('/dashboard')
            ->assertStatus(403)
            ->assertSee('Verify your email address');
    }

    private function user(bool $totp, bool $verified = true): User
    {
        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $user = UserFactory::createOne(['companies' => [], 'verified' => $verified, 'email' => 'jo@totp.test']);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->addCompany($company, CompanyRole::Owner);
        $user->setTotpSecret($totp ? 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' : '');
        $this->em->flush();

        return $user;
    }
}
