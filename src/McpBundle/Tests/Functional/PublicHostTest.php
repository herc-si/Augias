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

namespace Augias\McpBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\McpBundle\Entity\McpAccessToken;
use Augias\McpBundle\Entity\OAuthClient;
use Augias\McpBundle\OAuth\KeyManager;
use Augias\McpBundle\Repository\McpAccessTokenRepository;
use Augias\McpBundle\Repository\OAuthClientRepository;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use function bin2hex;
use function json_decode;
use function random_bytes;

/**
 * The MCP server answers on the instance's own host name, not only on
 * localhost: the SDK's DNS rebinding protection turned every authenticated
 * request from Claude into a 403 "Invalid Host header" on preprod.
 */
#[CoversNothing]
final class PublicHostTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    public function testAnAuthenticatedClientIsServedOnThePublicHostName(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->disableReboot();
        $jwt = $this->accessToken();

        $client->request('POST', '/_mcp', server: [
            'HTTP_HOST' => 'app.example.org',
            'HTTPS' => 'on',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], content: '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"claude-ai","version":"0.1.0"}}}');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('Augias', $body['result']['serverInfo']['name'] ?? null);
    }

    private function accessToken(): string
    {
        $container = self::getContainer();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);

        $oauthClient = new OAuthClient();
        $oauthClient->setName('Claude');
        $oauthClient->setRedirectUris(['https://claude.ai/api/mcp/auth_callback']);
        $oauthClient->setGrantTypes(['authorization_code']);
        $oauthClient->setScopes(['mcp:read']);
        $oauthClient->setTokenEndpointAuthMethod('none');
        $clients = $container->get(OAuthClientRepository::class);
        self::assertInstanceOf(OAuthClientRepository::class, $clients);
        $clients->save($oauthClient);

        $token = new McpAccessToken();
        $token->setOAuthClient($oauthClient);
        $token->setUser($user);
        // The company installed before the kernel restarted belongs to another manager.
        $company = $container->get('doctrine')->getManager()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $token->setCompany($company);
        $token->setIdentifier('jti-host-' . bin2hex(random_bytes(8)));
        $token->setScopeValues(['mcp:read']);
        $token->setExpiresAt(CarbonImmutable::now()->addHour());
        $tokens = $container->get(McpAccessTokenRepository::class);
        self::assertInstanceOf(McpAccessTokenRepository::class, $tokens);
        $tokens->persistNewAccessToken($token);

        $keys = $container->get(KeyManager::class);
        self::assertInstanceOf(KeyManager::class, $keys);
        $keys->generate();
        $token->setPrivateKey($keys->getPrivateKey());

        return $token->toString();
    }
}
