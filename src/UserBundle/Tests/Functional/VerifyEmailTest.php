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

use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use function html_entity_decode;
use function preg_match;

/**
 * The whole loop: sign up, then open the link Augias mailed, in another
 * browser — a phone's mail app, with no session.
 *
 * Written after a link was refused on the test instance (29/09/2026) without
 * leaving a trace. It could not be reproduced there afterwards; this keeps the
 * loop honest, and VerifyEmail now logs any refusal that is not an expiry.
 */
#[Group('functional')]
final class VerifyEmailTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private const string EMAIL = 'someone.new@example.org';

    public function testTheMailedLinkVerifiesTheAddress(): void
    {
        $_SERVER['AUGIAS_ALLOW_REGISTRATION'] = $_ENV['AUGIAS_ALLOW_REGISTRATION'] = '1';

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->disableReboot();

        $crawler = $client->request(Request::METHOD_GET, '/register');
        $client->submit($crawler->filter('form')->form([
            'register[email]' => self::EMAIL,
            'register[plainPassword]' => 'Sup3rStr0ngP@ssw0rd',
            'register[acceptTerms]' => '1',
        ]));
        self::assertResponseRedirects();

        $link = null;

        foreach (self::getMailerMessages() as $message) {
            // As a mail client reads it: the href in the HTML, entities decoded.
            if ($message instanceof TemplatedEmail && preg_match('#href="([^"]*/verify\?[^"]*)"#', (string) $message->getHtmlBody(), $match) === 1) {
                $link = html_entity_decode($match[1]);
            }
        }

        self::assertIsString($link, 'No verification email was sent.');

        $client->getCookieJar()->clear();
        $client->request(Request::METHOD_GET, $link);

        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        $user = $registry->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);

        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isVerified());
    }

    #[After]
    public function allowRegistrationOnlyHere(): void
    {
        unset($_SERVER['AUGIAS_ALLOW_REGISTRATION'], $_ENV['AUGIAS_ALLOW_REGISTRATION']);
    }
}
