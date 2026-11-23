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
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Mailer\Test\InteractsWithMailer;
use Zenstruck\Mailer\Test\TestEmail;

/**
 * A holder who lost their phone and their backup codes asks support; once
 * support has checked who is asking, the reset removes every second factor,
 * tells the holder, and the next sign-in leads to setting up the app again
 * (Rémi, 23/11/2026).
 */
#[CoversClass(AccountSecurity::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class SecondFactorResetTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;
    use InteractsWithMailer;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheResetRemovesEverySecondFactorTellsTheHolderAndLeadsToTheSetUp(): void
    {
        $user = $this->account();
        $now = new DateTimeImmutable();
        $this->em->persist(new TrustedDevice($user, 42, $now, $now->modify('+30 days'), 'Firefox/133.0', null));
        $this->em->flush();

        $security = self::getContainer()->get(AccountSecurity::class);
        self::assertInstanceOf(AccountSecurity::class, $security);
        $security->secondFactorReset($user);

        $this->mailer()->assertEmailSentTo('jo@reset.test', static function (TestEmail $email): void {
            $email->assertSubject('The two-factor authentication of your Augias account was reset');
        });

        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertFalse($fresh->isTotpAuthenticationEnabled());
        self::assertFalse($fresh->isEmailAuthEnabled());
        self::assertSame([], $fresh->getBackupCodes());
        self::assertSame(0, $this->em->getRepository(TrustedDevice::class)->count([]));

        $this->browser()
            ->interceptRedirects()
            ->visit('/login')
            ->fillField('_username', 'jo@reset.test')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->followRedirects()
            ->visit('/dashboard')
            ->assertOn('/profile/2fa')
            ->assertSee('Authenticator app required');
    }

    private function account(): User
    {
        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $user = UserFactory::createOne(['companies' => [], 'email' => 'jo@reset.test']);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user->addCompany($company, CompanyRole::Owner);
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setTotpSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        $user->enableEmailAuth(true);
        $user->setBackUpCodes(['A1B2C3-D4E5F6']);
        $this->em->flush();

        return $user;
    }
}
