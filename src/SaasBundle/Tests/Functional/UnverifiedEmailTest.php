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

namespace Augias\SaasBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SaasBundle\EventSubscriber\UnverifiedEmailListener;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Action\Security\ResendVerificationEmail;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * 05/10/2026, test instance: an account that had not verified its email
 * address bought a plan. Until it is verified, the application is closed.
 */
#[CoversClass(UnverifiedEmailListener::class)]
#[CoversClass(ResendVerificationEmail::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class UnverifiedEmailTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testNothingOpensUntilTheAddressIsVerified(): void
    {
        $user = $this->user(verified: false);

        $browser = $this->browser()->actingAs($user);
        foreach (['/dashboard', '/billing/subscription/plans', '/billing/subscription/activate', '/clients'] as $page) {
            $browser
                ->visit($page)
                ->assertStatus(403)
                ->assertSee('Verify your email address')
                ->assertSee('unverified@example.test');
        }
    }

    public function testANewLinkCanBeAskedForOnceAMinute(): void
    {
        $user = $this->user(verified: false);

        $browser = $this->browser()->actingAs($user)->visit('/dashboard');
        $token = $browser->crawler()->filter('form[action="/verify/resend"] input[name=_token]')->attr('value');

        $browser
            ->post('/verify/resend', ['body' => ['_token' => $token]])
            ->assertSee('A new link is on its way')
            ->post('/verify/resend', ['body' => ['_token' => $token]])
            ->assertSee('Wait a minute before asking for another');
    }

    public function testSigningOutStaysOpen(): void
    {
        $user = $this->user(verified: false);

        $browser = $this->browser()->actingAs($user)->visit('/dashboard');
        $token = $browser->crawler()->filter('input[name=_csrf_token]')->attr('value');

        $browser
            ->interceptRedirects()
            ->post('/logout', ['body' => ['_csrf_token' => $token]])
            ->assertRedirected();
    }

    public function testAVerifiedAccountIsLetThrough(): void
    {
        $user = $this->user(verified: true);

        $this->browser()
            ->actingAs($user)
            ->visit('/dashboard')
            ->assertNotSee('Verify your email address');
    }

    private function user(bool $verified): User
    {
        $company = CompanyFactory::createOne(['name' => 'Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $user = UserFactory::createOne(['companies' => [], 'verified' => $verified, 'email' => $verified ? 'verified@example.test' : 'unverified@example.test']);
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->addCompany($company, CompanyRole::Owner);
        $this->em->flush();

        return $user;
    }
}
