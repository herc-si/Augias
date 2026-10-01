<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp;

use Augias\ElectronicInvoicingBundle\Entity\SuperPdpAuthorization;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpAccessTokens;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApiException;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApplication;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\TokenCipher;
use Augias\ElectronicInvoicingBundle\Repository\SuperPdpAuthorizationRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Ulid;
use function json_encode;
use function parse_str;

#[CoversClass(SuperPdpAccessTokens::class)]
#[CoversClass(SuperPdpAuthorizationRepository::class)]
final class SuperPdpAccessTokensTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private MockClock $clock;

    private TokenCipher $cipher;

    /**
     * @var list<array<string, string>>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-01 12:00:00 UTC');
        $this->cipher = new TokenCipher('test-secret');
    }

    public function testOwnCredentialsGetAFreshTokenEveryTime(): void
    {
        $tokens = $this->tokens([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'first'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'second'])),
        ]);

        $config = ['client_id' => 'id', 'client_secret' => 'secret'];

        self::assertTrue($tokens->hasCredentials($config));
        self::assertFalse($tokens->isConnection($config));
        self::assertSame('first', $tokens->accessToken($config));
        self::assertSame('second', $tokens->accessToken($config));
    }

    public function testNoCredentialsAtAll(): void
    {
        $tokens = $this->tokens([]);

        self::assertFalse($tokens->hasCredentials([]));
        self::assertFalse($tokens->hasCredentials(['authorization' => 'not-an-id']));

        $this->expectException(SuperPdpApiException::class);
        $this->expectExceptionMessage('einvoicing.provider.super_pdp.missing_credentials');

        $tokens->accessToken([]);
    }

    public function testAStoredAccessTokenIsUsedWhileItLasts(): void
    {
        $id = $this->connect(expiresAt: new DateTimeImmutable('2026-10-01 12:20:00 UTC'));
        $tokens = $this->tokens([]);

        self::assertSame('stored-access', $tokens->accessToken(['authorization' => (string) $id]));
        self::assertTrue($tokens->isConnected(['authorization' => (string) $id]));
    }

    /**
     * The refresh token works once: the new one SUPER PDP hands back is the
     * one kept, and the next call does not go back to SUPER PDP.
     */
    public function testAnExpiredAccessTokenIsRefreshedAndTheRefreshTokenRotated(): void
    {
        $id = $this->connect(expiresAt: new DateTimeImmutable('2026-10-01 12:00:30 UTC'));
        $tokens = $this->tokens([
            fn (string $method, string $url, array $options): MockResponse => $this->record($options, ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 1800]),
        ]);

        self::assertSame('new-access', $tokens->accessToken(['authorization' => (string) $id]));
        self::assertSame('refresh_token', $this->sent[0]['grant_type']);
        self::assertSame('stored-refresh', $this->sent[0]['refresh_token']);
        self::assertSame('app-id', $this->sent[0]['client_id']);

        $stored = $this->repository()->readTokens($id);
        self::assertNotNull($stored);
        self::assertSame('new-refresh', $this->cipher->decrypt((string) $stored['refreshToken']));
        self::assertEquals(new DateTimeImmutable('2026-10-01 12:30:00 UTC'), $stored['expiresAt']);

        // No more responses queued: a second call reaching SUPER PDP would fail.
        self::assertSame('new-access', $tokens->accessToken(['authorization' => (string) $id]));
    }

    public function testARefusedRefreshTokenDisconnectsTheCompany(): void
    {
        $id = $this->connect(expiresAt: new DateTimeImmutable('2026-10-01 11:00:00 UTC'));
        $tokens = $this->tokens([
            static fn (): MockResponse => new MockResponse((string) json_encode(['error' => 'invalid_grant']), ['http_code' => 400]),
        ]);

        try {
            $tokens->accessToken(['authorization' => (string) $id]);
            self::fail('Expected a SuperPdpApiException to be thrown.');
        } catch (SuperPdpApiException $e) {
            self::assertTrue($e->isInvalidGrant());
            self::assertSame('einvoicing.provider.super_pdp.disconnected', $e->getMessage());
        }

        self::assertFalse($tokens->isConnected(['authorization' => (string) $id]));
        self::assertNull($this->repository()->readTokens($id)['refreshToken'] ?? null);
    }

    /**
     * SUPER PDP out of reach says nothing about the connection: the refresh
     * token was not spent, and the next attempt uses it.
     */
    public function testAnOutageKeepsTheConnection(): void
    {
        $id = $this->connect(expiresAt: new DateTimeImmutable('2026-10-01 11:00:00 UTC'));
        $tokens = $this->tokens([
            static fn (): MockResponse => new MockResponse('', ['http_code' => 503]),
            fn (string $method, string $url, array $options): MockResponse => $this->record($options, ['access_token' => 'new-access', 'refresh_token' => 'new-refresh']),
        ]);

        try {
            $tokens->accessToken(['authorization' => (string) $id]);
            self::fail('Expected a SuperPdpApiException to be thrown.');
        } catch (SuperPdpApiException $e) {
            self::assertFalse($e->isInvalidGrant());
        }

        self::assertTrue($tokens->isConnected(['authorization' => (string) $id]));
        self::assertSame('new-access', $tokens->accessToken(['authorization' => (string) $id]));
        self::assertSame('stored-refresh', $this->sent[0]['refresh_token']);
    }

    /**
     * Another process is refreshing: this one waits for its result rather
     * than spending the same refresh token a second time.
     */
    public function testARefreshAlreadyUnderWayIsWaitedFor(): void
    {
        $id = $this->connect(expiresAt: new DateTimeImmutable('2026-10-01 11:00:00 UTC'));
        $repository = $this->repository();
        self::assertTrue($repository->claimRefresh($id, $this->clock->now(), $this->clock->now()->modify('+30 seconds')));

        // What the other process writes once SUPER PDP has answered it.
        $repository->storeTokens($id, $this->cipher->encrypt('their-refresh'), $this->cipher->encrypt('their-access'), new DateTimeImmutable('2026-10-01 12:30:00 UTC'));
        $repository->claimRefresh($id, $this->clock->now(), $this->clock->now()->modify('+30 seconds'));

        self::assertSame('their-access', $this->tokens([])->accessToken(['authorization' => (string) $id]));
    }

    public function testAClaimLeftBehindByADeadProcessLapses(): void
    {
        $id = $this->connect(expiresAt: new DateTimeImmutable('2026-10-01 11:00:00 UTC'));
        $repository = $this->repository();

        self::assertTrue($repository->claimRefresh($id, $this->clock->now(), $this->clock->now()->modify('+30 seconds')));
        self::assertFalse($repository->claimRefresh($id, $this->clock->now(), $this->clock->now()->modify('+30 seconds')));

        $this->clock->modify('+31 seconds');

        self::assertTrue($repository->claimRefresh($id, $this->clock->now(), $this->clock->now()->modify('+30 seconds')));
    }

    private function connect(DateTimeImmutable $expiresAt): Ulid
    {
        $authorization = new SuperPdpAuthorization(
            $this->cipher->encrypt('stored-refresh'),
            $this->cipher->encrypt('stored-access'),
            $expiresAt,
            new DateTimeImmutable('2026-09-01 UTC'),
        );
        $authorization->setCompany($this->company);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($authorization);
        $entityManager->flush();

        $id = $authorization->getId();
        self::assertInstanceOf(Ulid::class, $id);

        return $id;
    }

    /**
     * @param list<callable> $responses
     */
    private function tokens(array $responses): SuperPdpAccessTokens
    {
        return new SuperPdpAccessTokens(
            new SuperPdpClient(new MockHttpClient($responses)),
            new SuperPdpApplication('app-id', 'app-secret'),
            $this->repository(),
            $this->cipher,
            $this->clock,
            new NullLogger(),
        );
    }

    private function repository(): SuperPdpAuthorizationRepository
    {
        return self::getContainer()->get(SuperPdpAuthorizationRepository::class);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $response
     */
    private function record(array $options, array $response): MockResponse
    {
        parse_str((string) $options['body'], $body);
        /** @var array<string, string> $body */
        $this->sent[] = $body;

        return new MockResponse((string) json_encode($response));
    }
}
