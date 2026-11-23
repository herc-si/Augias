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
use Augias\UserBundle\Entity\TrustedDevice;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Security\AccountSecurity;
use Augias\UserBundle\Test\Factory\UserFactory;
use OTPHP\TOTP;
use Override;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\KernelBrowser;
use Zenstruck\Browser\Test\HasBrowser;
use function sprintf;

/**
 * The authenticator app is required on every account of the service; what
 * keeps it bearable is "trust this device": the code is asked once every
 * 30 days on a given browser (Rémi, 23/11/2026). A lost phone is what
 * the backup codes are for.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class TrustedDeviceTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private const string SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testATrustedDeviceIsNotAskedForTheCodeAgain(): void
    {
        $this->account();

        $browser = $this->signIn($this->browser())->assertOn('/2fa')
            ->assertSee('Trust this device for 30 days')
            ->assertSee('Enter one of your backup codes instead');
        $browser
            ->fillField('_auth_code', $this->code())
            ->checkField('_trusted')
            ->click('button[type=submit]')
            ->assertNotOn('/2fa');
        self::assertNotNull($browser->client()->getCookieJar()->get('trusted_device'));

        $devices = $this->em->getRepository(TrustedDevice::class)->findAll();
        self::assertCount(1, $devices);
        $firstUse = $devices[0]->getLastUsedAt();

        $this->forgetSession($browser);

        $this->signIn($browser)->assertNotOn('/2fa')->assertNotOn('/login');

        $this->em->clear();
        $devices = $this->em->getRepository(TrustedDevice::class)->findAll();
        self::assertCount(1, $devices, 'Signing in again on the same device adds no row.');
        self::assertGreaterThanOrEqual($firstUse, $devices[0]->getLastUsedAt());
    }

    public function testRemovingOneDeviceAsksItForTheCodeAgain(): void
    {
        $user = $this->account();

        $browser = $this->signIn($this->browser())->assertOn('/2fa');
        $browser
            ->fillField('_auth_code', $this->code())
            ->checkField('_trusted')
            ->click('button[type=submit]')
            ->assertNotOn('/2fa');

        self::assertSame(1, $this->em->createQuery(sprintf('DELETE FROM %s d', TrustedDevice::class))->execute());

        $this->forgetSession($browser);

        $this->signIn($browser)->assertOn('/2fa');
        self::assertSame(0, $this->em->getRepository(TrustedDevice::class)->count(['user' => $user]));
    }

    public function testForgettingAllDevicesAsksForTheCodeAgainOnATrustedOne(): void
    {
        $user = $this->account();

        $browser = $this->signIn($this->browser())->assertOn('/2fa');
        $browser
            ->fillField('_auth_code', $this->code())
            ->checkField('_trusted')
            ->click('button[type=submit]')
            ->assertNotOn('/2fa');

        $security = self::getContainer()->get(AccountSecurity::class);
        self::assertInstanceOf(AccountSecurity::class, $security);
        $security->forgetTrustedDevices($user);

        $this->forgetSession($browser);

        $this->signIn($browser)->assertOn('/2fa');
    }

    public function testAnUntrustedDeviceIsAskedForTheCodeAgain(): void
    {
        $this->account();

        $browser = $this->signIn($this->browser())->assertOn('/2fa');
        $browser
            ->fillField('_auth_code', $this->code())
            ->click('button[type=submit]')
            ->assertNotOn('/2fa');

        $this->forgetSession($browser);

        $this->signIn($browser)->assertOn('/2fa');
    }

    public function testABackupCodeOpensTheAccountWhenThePhoneIsLost(): void
    {
        $this->account();

        $this->signIn($this->browser())
            ->assertOn('/2fa')
            ->fillField('_auth_code', 'A1B2C3-D4E5F6')
            ->click('button[type=submit]')
            ->assertNotOn('/2fa')
            ->assertNotOn('/login');
    }

    private function signIn(KernelBrowser $browser): KernelBrowser
    {
        return $browser
            ->visit('/login')
            ->fillField('_username', 'jo@trusted.test')
            ->fillField('_password', 'password')
            ->click('Sign in');
    }

    /**
     * Closing the browser: the session goes, the trusted-device cookie stays.
     */
    private function forgetSession(KernelBrowser $browser): void
    {
        $jar = $browser->client()->getCookieJar();

        foreach ($jar->all() as $cookie) {
            if ($cookie->getName() !== 'trusted_device') {
                $jar->expire($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }

    private function code(): string
    {
        return TOTP::createFromSecret(self::SECRET)->now();
    }

    private function account(): User
    {
        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $user = UserFactory::createOne(['companies' => [], 'email' => 'jo@trusted.test']);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user->addCompany($company, CompanyRole::Owner);
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setTotpSecret(self::SECRET);
        $user->setBackUpCodes(['A1B2C3-D4E5F6']);
        $this->em->flush();

        return $user;
    }
}
