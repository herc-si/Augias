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
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApiException;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function json_encode;
use function parse_str;
use function parse_url;

#[CoversClass(SuperPdpClient::class)]
final class SuperPdpClientTest extends TestCase
{
    public function testGetAccessTokenReturnsTheToken(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])));

        $client = new SuperPdpClient($httpClient);

        self::assertSame('a-token', $client->getAccessToken('id', 'secret'));
    }

    public function testGetAccessTokenThrowsWhenTokenIsMissing(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['token_type' => 'Bearer'])));

        $client = new SuperPdpClient($httpClient);

        $this->expectException(SuperPdpApiException::class);

        $client->getAccessToken('id', 'secret');
    }

    public function testSendInvoiceReturnsTheDecodedResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['id' => 42, 'events' => []])));

        $client = new SuperPdpClient($httpClient);

        $response = $client->sendInvoice('a-token', '%PDF-1.7 ...', 'inv-1');

        self::assertSame(42, $response['id']);
    }

    public function testSendInvoiceThrowsWithTheApiErrorMessage(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            (string) json_encode(['code' => 12, 'http_status_code' => 400, 'message' => 'Invalid document']),
            ['http_code' => 400],
        ));

        $client = new SuperPdpClient($httpClient);

        try {
            $client->sendInvoice('a-token', 'garbage', 'inv-1');
            self::fail('Expected a SuperPdpApiException to be thrown.');
        } catch (SuperPdpApiException $e) {
            self::assertStringContainsString('Invalid document', $e->getMessage());
            self::assertSame(12, $e->getApiCode());
        }
    }

    public function testGetInvoiceReturnsTheDecodedResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['id' => 42, 'events' => [['status_code' => 'fr:200']]])));

        $client = new SuperPdpClient($httpClient);

        $response = $client->getInvoice('a-token', '42');

        self::assertSame('fr:200', $response['events'][0]['status_code']);
    }

    /**
     * Regression test: `expand[]=en_invoice` alone leaves `en_invoice.seller`
     * null on a real received invoice (confirmed against SUPER PDP's sandbox
     * API) — `en_invoice.seller` must also be requested explicitly.
     */
    public function testListIncomingInvoicesRequestsTheSellerExpansion(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertStringContainsString('direction=in', $url);
            self::assertStringContainsString('expand[0]=en_invoice', $url);
            self::assertStringContainsString('expand[1]=en_invoice.seller', $url);

            return new MockResponse((string) json_encode(['data' => [], 'count' => 0, 'has_before' => false, 'has_after' => false]));
        });

        $client = new SuperPdpClient($httpClient);

        $response = $client->listIncomingInvoices('a-token');

        self::assertSame([], $response['data']);
    }

    public function testListIncomingInvoicesPassesTheStartingAfterIdCursor(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertStringContainsString('starting_after_id=42', $url);

            return new MockResponse((string) json_encode(['data' => [], 'count' => 0, 'has_before' => false, 'has_after' => false]));
        });

        $client = new SuperPdpClient($httpClient);

        $client->listIncomingInvoices('a-token', 42);
    }

    public function testDownloadInvoiceDocumentReturnsTheRawContentAndContentType(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertStringContainsString('format=factur-x', $url);

            return new MockResponse('%PDF-1.7 ...', ['response_headers' => ['content-type' => 'application/pdf']]);
        });

        $client = new SuperPdpClient($httpClient);

        $document = $client->downloadInvoiceDocument('a-token', '42');

        self::assertSame('%PDF-1.7 ...', $document['content']);
        self::assertSame('application/pdf', $document['content_type']);
    }

    public function testTheAuthorizationUrlCarriesPkceAndFillsInTheCompany(): void
    {
        $url = new SuperPdpClient(new MockHttpClient())->authorizationUrl('app-id', 'https://augias.test/callback', 'the-state', 'the-challenge', 'owner@example.com', '732829320');

        self::assertStringStartsWith('https://api.superpdp.tech/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'response_type' => 'code',
            'client_id' => 'app-id',
            'redirect_uri' => 'https://augias.test/callback',
            'state' => 'the-state',
            'code_challenge' => 'the-challenge',
            'code_challenge_method' => 'S256',
            'login_hint' => 'owner@example.com',
            'superpdp_company_number' => '732829320',
            'superpdp_company_number_scheme' => 'fr_siren',
        ], $query);
    }

    /**
     * The number and its scheme go together: without a SIREN, neither.
     */
    public function testTheAuthorizationUrlLeavesOutWhatIsNotKnown(): void
    {
        $url = new SuperPdpClient(new MockHttpClient())->authorizationUrl('app-id', 'https://augias.test/callback', 'the-state', 'the-challenge');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('login_hint', $query);
        self::assertArrayNotHasKey('superpdp_company_number', $query);
        self::assertArrayNotHasKey('superpdp_company_number_scheme', $query);
    }

    public function testTheCodeIsTradedForTokens(): void
    {
        $sent = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options['body'];

            return new MockResponse((string) json_encode(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 1800, 'token_type' => 'Bearer']));
        });
        $now = new DateTimeImmutable('2026-10-01 12:00:00');

        $tokens = new SuperPdpClient($httpClient)->exchangeAuthorizationCode('app-id', 'app-secret', 'the-code', 'https://augias.test/callback', 'the-verifier', $now);

        self::assertSame('access', $tokens->accessToken);
        self::assertSame('refresh', $tokens->refreshToken);
        self::assertEquals(new DateTimeImmutable('2026-10-01 12:30:00'), $tokens->expiresAt);
        self::assertIsString($sent);
        parse_str($sent, $body);
        self::assertSame('authorization_code', $body['grant_type']);
        self::assertSame('the-code', $body['code']);
        self::assertSame('the-verifier', $body['code_verifier']);
        self::assertSame('https://augias.test/callback', $body['redirect_uri']);
    }

    public function testARefreshThatIsRefusedSaysSo(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(
            (string) json_encode(['error' => 'invalid_grant', 'error_description' => 'The refresh token is invalid.']),
            ['http_code' => 400],
        ));

        try {
            new SuperPdpClient($httpClient)->refreshTokens('app-id', 'app-secret', 'spent', new DateTimeImmutable());
            self::fail('Expected a SuperPdpApiException to be thrown.');
        } catch (SuperPdpApiException $e) {
            self::assertTrue($e->isInvalidGrant());
            self::assertSame('The refresh token is invalid.', $e->getMessage());
        }
    }

    public function testATokenResponseWithoutARefreshTokenIsAnError(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'access'])));

        $this->expectException(SuperPdpApiException::class);

        new SuperPdpClient($httpClient)->refreshTokens('app-id', 'app-secret', 'refresh', new DateTimeImmutable());
    }
}
