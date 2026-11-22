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

use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Entity\ResetPasswordRequest;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Mailer\Test\InteractsWithMailer;
use Zenstruck\Mailer\Test\TestEmail;

/**
 * Taking an account back after someone took the mailbox or a session: a new
 * password, or a new address, leaves the intruder no way in — trusted
 * devices forgotten, pending reset links void — and the holder is told at
 * the address the account had (Rémi, 22/11/2026).
 */
#[Group('functional')]
final class AccountTakeoverTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;
    use InteractsWithMailer;

    public function testANewPasswordForgetsTrustedDevicesAndPendingResetLinks(): void
    {
        $user = $this->account();
        $this->em->persist(new ResetPasswordRequest($user, new DateTimeImmutable('+1 hour'), 'selector12345678901', 'hashed-token'));
        $this->em->flush();

        $this->browser()
            ->actingAs($user)
            ->visit('/profile/change-password')
            ->fillField('change_password[currentPassword]', 'password')
            ->fillField('change_password[plainPassword][first]', 'another-strong-password')
            ->fillField('change_password[plainPassword][second]', 'another-strong-password')
            ->interceptRedirects()
            ->click('button.btn-save')
            ->assertRedirected();

        $this->mailer()->assertEmailSentTo('jo@takeover.test', static function (TestEmail $email): void {
            $email->assertSubject('Your Augias account password was changed');
        });

        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertSame(1, $fresh->getTrustedTokenVersion(), 'Devices trusted before must ask for the second factor again.');
        self::assertCount(0, $this->em->getRepository(ResetPasswordRequest::class)->findAll(), 'A reset link sent before must no longer work.');
    }

    public function testANewAddressIsConfirmedBeforeItCountsAndTheOldOneIsTold(): void
    {
        $user = $this->account();

        $this->browser()
            ->actingAs($user)
            ->visit('/profile/edit')
            ->fillField('profile[firstName]', 'Jo')
            ->fillField('profile[lastName]', 'Doe')
            ->fillField('profile[mobile]', '0601020304')
            ->fillField('profile[email]', 'someone@else.test')
            ->fillField('profile[current_password]', 'password')
            ->interceptRedirects()
            ->click('button.btn-save')
            ->assertRedirected();

        $this->mailer()
            ->assertEmailSentTo('jo@takeover.test', static function (TestEmail $email): void {
                $email
                    ->assertSubject('Your Augias account address was changed')
                    ->assertHtmlContains('someone@else.test');
            })
            ->assertEmailSentTo('someone@else.test', static function (TestEmail $email): void {
                $email->assertSubject('Confirm your email address');
            });

        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertSame('someone@else.test', $fresh->getEmail());
        self::assertFalse($fresh->isVerified());
        self::assertSame(1, $fresh->getTrustedTokenVersion());
    }

    public function testSavingTheProfileWithTheSameAddressSendsNothing(): void
    {
        $user = $this->account();

        $this->browser()
            ->actingAs($user)
            ->visit('/profile/edit')
            ->fillField('profile[firstName]', 'Jo')
            ->fillField('profile[lastName]', 'Doe')
            ->fillField('profile[mobile]', '0601020304')
            ->fillField('profile[current_password]', 'password')
            ->interceptRedirects()
            ->click('button.btn-save')
            ->assertRedirected();

        $this->mailer()->assertNoEmailSent();

        $this->em->clear();
        self::assertTrue($this->em->find(User::class, $user->getId())?->isVerified());
    }

    private function account(): User
    {
        $user = UserFactory::createOne([
            'email' => 'jo@takeover.test',
            'companies' => [CompanyFactory::createOne(['name' => 'Alpha Inc'])],
            'verified' => true,
        ]);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user->setPassword($hasher->hashPassword($user, 'password'));
        $this->em->flush();

        return $user;
    }
}
