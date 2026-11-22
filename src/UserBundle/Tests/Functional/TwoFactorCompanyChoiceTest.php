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
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Someone with two companies and a second factor picks a company once, and
 * lands in it — where the first pick used to bring the list back (Rémi,
 * 19/11/2026).
 */
#[Group('functional')]
final class TwoFactorCompanyChoiceTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testTheFirstCompanyPickedAfterTheSecondFactorIsOpened(): void
    {
        $alpha = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $user = $this->accountWithEmailCode([$alpha, CompanyFactory::createOne(['name' => 'Beta Inc'])]);

        $browser = $this->browser()
            ->visit('/login')
            ->fillField('_username', 'jo@two-factor.test')
            ->fillField('_password', 'password')
            ->click('Sign in')
            ->assertOn('/2fa');

        $this->em->clear();
        $code = $this->em->find(User::class, $user->getId())?->getEmailAuthCode();
        self::assertNotNull($code);

        $browser
            ->fillField('_auth_code', $code)
            ->click('button[type=submit]')
            ->assertOn('/select-company')
            ->assertSee('Alpha Inc')
            ->visit('/select-company/' . $alpha->getId())
            ->assertOn('/dashboard');
    }

    /**
     * @param list<Company> $companies
     */
    private function accountWithEmailCode(array $companies): User
    {
        $user = UserFactory::createOne(['email' => 'jo@two-factor.test', 'companies' => $companies]);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->enableEmailAuth(true);
        $this->em->flush();

        return $user;
    }
}
