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

use const PHP_URL_QUERY;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApplication;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnectionFailed;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnector;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\TokenCipher;
use Augias\ElectronicInvoicingBundle\Repository\SuperPdpAuthorizationRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;
use function array_keys;
use function base64_encode;
use function hash;
use function is_string;
use function json_encode;
use function parse_str;
use function parse_url;
use function rtrim;
use function strtr;

#[CoversClass(SuperPdpConnector::class)]
final class SuperPdpConnectorTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private MockClock $clock;

    private Session $session;

    private TokenCipher $cipher;

    /**
     * @var list<array{url: string, body: array<string, string>}>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-01 12:00:00 UTC');
        $this->session = new Session(new MockArraySessionStorage());
        $this->cipher = new TokenCipher('test-secret');
    }

    /**
     * The user lands on SUPER PDP with their e-mail and the company's SIREN
     * filled in, and a PKCE challenge for the verifier kept in their session.
     */
    public function testStartSendsTheUserToSuperPdp(): void
    {
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'SIRET', 'value' => '732 829 320 00074']);
        $setting = $this->setting();

        $url = $this->connector([])->start($setting, $this->session, 'owner@example.com');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('app-id', $query['client_id']);
        self::assertSame('owner@example.com', $query['login_hint']);
        self::assertSame('732829320', $query['superpdp_company_number']);
        self::assertStringEndsWith('/electronic-invoicing/super-pdp/callback', (string) $query['redirect_uri']);

        $pending = $this->session->get('_einvoicing_super_pdp_connect');
        self::assertIsArray($pending);
        self::assertIsString($query['state']);
        self::assertArrayHasKey($query['state'], $pending);
        self::assertSame((string) $setting->getId(), $pending[$query['state']]['setting']);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $pending[$query['state']]['verifier'], true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
    }

    /**
     * A number that is not a SIREN is not filled in: better none than a wrong
     * one the user has to notice and clear.
     */
    public function testAnInvalidSirenIsNotFilledIn(): void
    {
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'SIREN', 'value' => '123456789']);

        $url = $this->connector([])->start($this->setting(), $this->session, null);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('superpdp_company_number', $query);
        self::assertArrayNotHasKey('login_hint', $query);
    }

    public function testCompleteKeepsTheTokensAndPointsTheSettingAtThem(): void
    {
        $setting = $this->setting(['client_id' => 'pasted-id', 'client_secret' => 'pasted-secret']);
        $connector = $this->connector([
            fn (string $method, string $url, array $options): MockResponse => $this->record($url, $options, ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 1800]),
        ]);
        $state = $this->stateOf($connector->start($setting, $this->session, null));
        $verifier = $this->session->get('_einvoicing_super_pdp_connect')[$state]['verifier'];

        $connector->complete($this->session, $state, 'the-code');
        $this->entityManager()->flush();

        self::assertSame('authorization_code', $this->sent[0]['body']['grant_type']);
        self::assertSame($verifier, $this->sent[0]['body']['code_verifier']);
        self::assertSame(['authorization'], array_keys($setting->getSettings()));

        $id = $setting->getSettings()['authorization'];
        self::assertTrue(is_string($id));
        $stored = self::getContainer()->get(SuperPdpAuthorizationRepository::class)->readTokens(Ulid::fromString($id));
        self::assertNotNull($stored);
        self::assertSame('refresh', $this->cipher->decrypt((string) $stored['refreshToken']));
        self::assertSame('access', $this->cipher->decrypt((string) $stored['accessToken']));
    }

    /**
     * Connecting again replaces the old connection, which is revoked on
     * SUPER PDP rather than left working.
     */
    public function testAnOlderConnectionIsRevoked(): void
    {
        $setting = $this->setting();
        $connector = $this->connector([
            fn (string $method, string $url, array $options): MockResponse => $this->record($url, $options, ['access_token' => 'access-1', 'refresh_token' => 'refresh-1']),
            fn (string $method, string $url, array $options): MockResponse => $this->record($url, $options, ['access_token' => 'access-2', 'refresh_token' => 'refresh-2']),
            fn (string $method, string $url, array $options): MockResponse => $this->record($url, $options, []),
        ]);

        $connector->complete($this->session, $this->stateOf($connector->start($setting, $this->session, null)), 'code-1');
        $this->entityManager()->flush();
        $first = $setting->getSettings()['authorization'];

        $connector->complete($this->session, $this->stateOf($connector->start($setting, $this->session, null)), 'code-2');
        $this->entityManager()->flush();

        self::assertStringEndsWith('/oauth2/revoke', $this->sent[2]['url']);
        self::assertSame('refresh-1', $this->sent[2]['body']['token']);
        self::assertNotSame($first, $setting->getSettings()['authorization']);
        self::assertNull(self::getContainer()->get(SuperPdpAuthorizationRepository::class)->readTokens(Ulid::fromString((string) $first)));
    }

    public function testAStateWorksOnce(): void
    {
        $setting = $this->setting();
        $connector = $this->connector([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'access', 'refresh_token' => 'refresh'])),
        ]);
        $state = $this->stateOf($connector->start($setting, $this->session, null));

        $connector->complete($this->session, $state, 'the-code');

        $this->expectException(SuperPdpConnectionFailed::class);
        $this->expectExceptionMessage('einvoicing.super_pdp.connect.expired');

        $connector->complete($this->session, $state, 'the-code');
    }

    public function testAnUnknownStateIsRefused(): void
    {
        $this->expectException(SuperPdpConnectionFailed::class);
        $this->expectExceptionMessage('einvoicing.super_pdp.connect.expired');

        $this->connector([])->complete($this->session, 'forged', 'the-code');
    }

    public function testAConnectionLeftOpenTooLongExpires(): void
    {
        $connector = $this->connector([]);
        $state = $this->stateOf($connector->start($this->setting(), $this->session, null));

        $this->clock->modify('+2 hours');

        $this->expectException(SuperPdpConnectionFailed::class);
        $this->expectExceptionMessage('einvoicing.super_pdp.connect.expired');

        $connector->complete($this->session, $state, 'the-code');
    }

    public function testACodeSuperPdpRefusesLeavesTheSettingAsItWas(): void
    {
        $setting = $this->setting(['client_id' => 'pasted-id', 'client_secret' => 'pasted-secret']);
        $connector = $this->connector([
            static fn (): MockResponse => new MockResponse((string) json_encode(['error' => 'invalid_grant']), ['http_code' => 400]),
        ]);
        $state = $this->stateOf($connector->start($setting, $this->session, null));

        try {
            $connector->complete($this->session, $state, 'bad-code');
            self::fail('Expected the connection to fail.');
        } catch (SuperPdpConnectionFailed $e) {
            self::assertSame('einvoicing.super_pdp.connect.failed', $e->getMessage());
        }

        self::assertSame(['client_id' => 'pasted-id', 'client_secret' => 'pasted-secret'], $setting->getSettings());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function setting(array $settings = []): ElectronicInvoiceProviderSetting
    {
        $setting = new ElectronicInvoiceProviderSetting()
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings($settings);
        $setting->setCompany($this->company);

        $this->entityManager()->persist($setting);
        $this->entityManager()->flush();

        return $setting;
    }

    /**
     * @param list<callable> $responses
     */
    private function connector(array $responses): SuperPdpConnector
    {
        $container = self::getContainer();

        return new SuperPdpConnector(
            new SuperPdpClient(new MockHttpClient($responses)),
            new SuperPdpApplication('app-id', 'app-secret'),
            $container->get(SuperPdpAuthorizationRepository::class),
            $container->get(TaxIdentifierRepository::class),
            $this->cipher,
            $this->entityManager(),
            $container->get(UrlGeneratorInterface::class),
            $this->clock,
            new NullLogger(),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function stateOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $response
     */
    private function record(string $url, array $options, array $response): MockResponse
    {
        parse_str((string) $options['body'], $body);
        /** @var array<string, string> $body */
        $this->sent[] = ['url' => $url, 'body' => $body];

        return new MockResponse((string) json_encode($response));
    }
}
