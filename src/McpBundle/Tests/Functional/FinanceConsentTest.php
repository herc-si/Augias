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
use Augias\McpBundle\Entity\ConsentGrant;
use Augias\McpBundle\Entity\OAuthClient;
use Augias\McpBundle\Repository\ConsentGrantRepository;
use Augias\McpBundle\Repository\OAuthClientRepository;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company's figures are a box of their own on the consent page, unticked:
 * an assistant gets mcp:finance only when the person ticks it.
 */
#[Group('functional')]
final class FinanceConsentTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    /**
     * @return iterable<string, array{bool, list<string>}>
     */
    public static function choices(): iterable
    {
        yield 'left unticked' => [false, ['mcp:read', 'mcp:write']];
        yield 'ticked' => [true, ['mcp:read', 'mcp:write', 'mcp:finance']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('choices')]
    public function testTheFiguresAreGrantedOnlyWhenTicked(bool $tick, array $expected): void
    {
        [$browser, $user] = $this->signedIn();
        $client = $this->oauthClient();

        $query = [
            'response_type' => 'code',
            'client_id' => $client->getIdentifier(),
            'redirect_uri' => 'http://localhost/cb',
            'scope' => 'mcp:read mcp:write mcp:finance',
            'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];

        $crawler = $browser->request(Request::METHOD_GET, '/oauth/authorize', $query);
        self::assertResponseIsSuccessful();

        $box = $crawler->filter('input[name="grant_finance"]');
        self::assertCount(1, $box);
        self::assertNull($box->attr('checked'), 'The figures box must open unticked.');

        $form = [
            '_csrf_token' => $crawler->filter('input[name="_csrf_token"]')->attr('value'),
            'company_id' => $this->company->getId()->toRfc4122(),
            'state' => 'xyz',
            'grant_write' => '1',
            'action' => 'approve',
        ];

        if ($tick) {
            $form['grant_finance'] = '1';
        }

        $browser->request(Request::METHOD_POST, '/oauth/authorize?' . http_build_query($query), $form);
        self::assertSame(Response::HTTP_FOUND, $browser->getResponse()->getStatusCode());

        $grants = self::getContainer()->get(ConsentGrantRepository::class);
        self::assertInstanceOf(ConsentGrantRepository::class, $grants);
        $company = self::getContainer()->get('doctrine')->getManager()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $grant = $grants->findGrant($client, $user, $company);
        self::assertInstanceOf(ConsentGrant::class, $grant);

        $granted = $grant->getScopes();
        sort($granted);
        sort($expected);
        self::assertSame($expected, $granted);
    }

    /**
     * @return array{KernelBrowser, User}
     */
    private function signedIn(): array
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        $browser->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $browser->loginUser($user);

        return [$browser, $user];
    }

    private function oauthClient(): OAuthClient
    {
        $repo = self::getContainer()->get(OAuthClientRepository::class);
        self::assertInstanceOf(OAuthClientRepository::class, $repo);

        $client = new OAuthClient();
        $client->setName('Finance consent agent');
        $client->setRedirectUris(['http://localhost/cb']);
        $client->setGrantTypes(['authorization_code']);
        $client->setScopes(['mcp:read', 'mcp:write', 'mcp:finance']);
        $client->setTokenEndpointAuthMethod('none');

        $repo->save($client);

        return $client;
    }
}
