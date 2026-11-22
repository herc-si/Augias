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
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Signing out is checked by where the request comes from, not by a token kept
 * in the session: a page opened before the session was lost — a deployment,
 * an expiry, a remember-me sign-in since — still signs out, where it used to
 * answer "Invalid CSRF token" (Rémi, 19/11/2026). Another site still cannot.
 */
#[Group('functional')]
final class LogoutTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testAPageFromAnEarlierSessionStillSignsOut(): void
    {
        $user = $this->user();

        $form = $this->browser()
            ->actingAs($user)
            ->visit('/dashboard')
            ->crawler()
            ->filter('form[action^="/logout"]');
        $action = (string) $form->attr('action');
        $token = $form->filter('input[name=_csrf_token]')->attr('value');

        // Another session: the one the page was rendered in is gone.
        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post($action, ['body' => ['_csrf_token' => $token], 'headers' => ['Origin' => 'http://localhost']])
            ->assertRedirectedTo('/login');
    }

    public function testAnotherSiteCannotSignTheUserOut(): void
    {
        $this->browser()
            ->actingAs($this->user())
            ->post('/logout', ['body' => ['_csrf_token' => 'csrf-token'], 'headers' => ['Origin' => 'https://elsewhere.example']])
            ->assertStatus(403)
            ->visit('/dashboard')
            ->assertSuccessful();
    }

    private function user(): User
    {
        return UserFactory::createOne([
            'email' => 'jo@logout.test',
            'companies' => [CompanyFactory::createOne(['name' => 'Alpha Inc'])],
        ]);
    }
}
