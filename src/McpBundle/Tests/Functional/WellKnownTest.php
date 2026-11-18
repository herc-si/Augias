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

use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\McpBundle\Action\WellKnownAgentSkillsIndex;
use Augias\McpBundle\Action\WellKnownAuthServer;
use Augias\McpBundle\Action\WellKnownProtectedResource;
use Augias\McpBundle\Action\WellKnownServerCard;
use Augias\McpBundle\OAuth\KeyManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(WellKnownAuthServer::class)]
#[CoversClass(WellKnownProtectedResource::class)]
#[CoversClass(WellKnownServerCard::class)]
#[CoversClass(WellKnownAgentSkillsIndex::class)]
#[Group('functional')]
final class WellKnownTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    public function testAuthorizationServerMetadata(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/.well-known/oauth-authorization-server');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('issuer', $data);
        self::assertArrayHasKey('authorization_endpoint', $data);
        self::assertArrayHasKey('token_endpoint', $data);
        self::assertArrayHasKey('registration_endpoint', $data);
        self::assertSame(['code'], $data['response_types_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $data['grant_types_supported']);
        self::assertSame(['S256'], $data['code_challenge_methods_supported']);
        self::assertSame(['mcp:read', 'mcp:write'], $data['scopes_supported']);
    }

    public function testProtectedResourceMetadata(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/.well-known/oauth-protected-resource');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('resource', $data);
        self::assertArrayHasKey('authorization_servers', $data);
        self::assertSame(['header'], $data['bearer_methods_supported']);
        self::assertSame(['mcp:read', 'mcp:write'], $data['scopes_supported']);
    }

    /**
     * RFC 9728 §3.1: the metadata of https://host/_mcp, at the path a client
     * builds from the resource. It was a 404, and Claude gave up there.
     */
    public function testProtectedResourceMetadataAtTheResourcesOwnPath(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/.well-known/oauth-protected-resource/_mcp');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertStringEndsWith('/_mcp', $data['resource']);
    }

    /**
     * The first request of every client carries no token: the 401 tells it
     * where to sign in, or it does not know and stops.
     */
    public function testARequestWithoutATokenIsToldWhereTheMetadataIs(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request(Request::METHOD_POST, '/_mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","id":1,"method":"initialize"}');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource", scope="mcp:read mcp:write"',
            $client->getResponse()->headers->get('WWW-Authenticate'),
        );
    }

    public function testAnInvalidTokenIsAlsoToldWhereTheMetadataIs(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        // Reading a token needs the signing keys; nothing else here makes them.
        $keys = self::getContainer()->get(KeyManager::class);
        self::assertInstanceOf(KeyManager::class, $keys);
        $keys->generate();

        $client->request(Request::METHOD_POST, '/_mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer not-a-token'], content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource"', (string) $client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testServerCard(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/.well-known/mcp/server-card.json');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertSame('Augias', $data['serverInfo']['name']);
        self::assertArrayHasKey('version', $data['serverInfo']);
        self::assertSame('http', $data['transport']['type']);
        self::assertStringEndsWith('/_mcp', $data['transport']['endpoint']);
        self::assertSame('oauth2', $data['authorization']['type']);
        self::assertSame(['mcp:read', 'mcp:write'], $data['authorization']['scopes']);
        self::assertArrayHasKey('tools', $data['capabilities']);
    }

    public function testAgentSkillsIndex(): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/.well-known/agent-skills/index.json');

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertArrayHasKey('$schema', $data);
        self::assertArrayHasKey('skills', $data);
        self::assertIsArray($data['skills']);
    }
}
