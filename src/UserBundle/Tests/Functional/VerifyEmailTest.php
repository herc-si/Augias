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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Action\Security\VerifyEmail;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\EmailVerifier;
use Augias\UserBundle\Test\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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

    /**
     * What failed on the test instance (29/09/2026, logged as "names no
     * account"): the link opened in a browser already signed in to another
     * account, whose company was selected. The company filter then scoped the
     * lookup of the account to that company, where the new one is not.
     */
    public function testTheLinkWorksWhileAnotherCompanyIsSelected(): void
    {
        $newcomer = UserFactory::createOne(['companies' => [CompanyFactory::createOne()], 'verified' => false]);
        $email = new TemplatedEmail()->to((string) $newcomer->getEmail())->htmlTemplate('@AugiasUser/Email/confirm_email.html.twig');
        self::getContainer()->get(EmailVerifier::class)->sendEmailConfirmation('_verify_email', $newcomer, $email);
        $link = $email->getContext()['signedUrl'] ?? null;
        self::assertIsString($link);

        // The session of the other account: its company selected, the filter on.
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        // A fresh request: nothing in memory, the lookup reaches the database.
        $entityManager->clear();

        $request = Request::create($link);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        self::getContainer()->get(VerifyEmail::class)($request);

        // The browser's company is still the one filtered on afterwards.
        self::assertSame(0, $entityManager->getRepository(User::class)->count(['id' => $newcomer->getId()]));

        $entityManager->getFilters()->disable('company');
        $entityManager->clear();
        $user = $entityManager->getRepository(User::class)->find($newcomer->getId());

        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isVerified());
    }

    public function testALinkToADeletedAccountSaysSo(): void
    {
        $gone = UserFactory::createOne(['companies' => [CompanyFactory::createOne()], 'verified' => false]);
        $email = new TemplatedEmail()->to((string) $gone->getEmail())->htmlTemplate('@AugiasUser/Email/confirm_email.html.twig');
        self::getContainer()->get(EmailVerifier::class)->sendEmailConfirmation('_verify_email', $gone, $email);
        $link = $email->getContext()['signedUrl'] ?? null;
        self::assertIsString($link);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        // Deleted since the mail went out, as from the operator console.
        $entityManager->remove($entityManager->find(User::class, $gone->getId()));
        $entityManager->flush();
        $entityManager->clear();

        $request = Request::create($link);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        self::getContainer()->get(VerifyEmail::class)($request);

        self::assertSame(['security.verify_email.flash.no_account'], $session->getFlashBag()->get('error'));
    }

    #[After]
    public function allowRegistrationOnlyHere(): void
    {
        unset($_SERVER['AUGIAS_ALLOW_REGISTRATION'], $_ENV['AUGIAS_ALLOW_REGISTRATION']);
    }
}
