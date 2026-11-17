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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use function Zenstruck\Foundry\faker;

#[Group('functional')]
final class RegisterTest extends WebTestCase
{
    use EnsureApplicationInstalled;
    use SolvesRegistrationChallenge;

    private const string PASSWORD = 'Sup3rStr0ngP@ssw0rd';

    /**
     * @var list<string>
     */
    private array $envOverrides = [];

    /**
     * A browser that solved the challenge the page handed out gets its account
     * and is signed in.
     */
    public function testRegistrationSucceedsWithASolvedChallenge(): void
    {
        $this->setEnv('AUGIAS_ALLOW_REGISTRATION', '1');

        $client = $this->bootClient();

        $this->submitRegistration($client, faker()->email(), $this->solvedChallenge($client));

        self::assertResponseRedirects();
        self::assertSame(1, $this->userCount());
    }

    /**
     * A script that never ran the proof of work is refused: the form re-renders
     * with the anti-bot message and no account is created.
     */
    public function testRegistrationIsRefusedWithoutTheProofOfWork(): void
    {
        $this->setEnv('AUGIAS_ALLOW_REGISTRATION', '1');

        $client = $this->bootClient();

        $this->submitRegistration($client, faker()->email(), null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('anti-bot check did not complete', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->userCount());
    }

    /**
     * One solved challenge, one account: replaying the answer for a second
     * address is refused.
     */
    public function testASolvedChallengeServesOnce(): void
    {
        $this->setEnv('AUGIAS_ALLOW_REGISTRATION', '1');

        $client = $this->bootClient();
        $payload = $this->solvedChallenge($client);

        $this->submitRegistration($client, faker()->email(), $payload);
        self::assertResponseRedirects();

        // Someone else, same answer: a fresh session.
        $client->getCookieJar()->clear();
        $this->submitRegistration($client, faker()->email(), $payload);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(1, $this->userCount());
    }

    public function testTheChallengeIsServedToAnyoneAndNeverCached(): void
    {
        $this->setEnv('AUGIAS_ALLOW_REGISTRATION', '1');

        $client = $this->bootClient();
        $client->request(Request::METHOD_GET, '/register/challenge');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $challenge = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($challenge);
        self::assertArrayHasKey('parameters', $challenge);
        self::assertArrayHasKey('signature', $challenge);
    }

    #[After]
    public function resetEnvOverrides(): void
    {
        foreach ($this->envOverrides as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }

        $this->envOverrides = [];
    }

    private function setEnv(string $name, string $value): void
    {
        $_SERVER[$name] = $_ENV[$name] = $value;
        $this->envOverrides[] = $name;
    }

    private function submitRegistration(KernelBrowser $client, string $email, ?string $proofOfWork): void
    {
        $this->signUp($client, [
            'register[email]' => $email,
            'register[plainPassword]' => self::PASSWORD,
            'register[acceptTerms]' => '1',
        ], $proofOfWork, false);
    }

    private function userCount(): int
    {
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');

        return $registry->getRepository(User::class)->count([]);
    }

    private function bootClient(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->disableReboot();

        return $client;
    }
}
