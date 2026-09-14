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

namespace Augias\SaasBundle\Tests\Functional;

use Augias\CoreBundle\Feature\NullUpgradePromptProvider;
use Augias\CoreBundle\Feature\UpgradePromptProvider;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies the SaaS feature-gate short-circuits the UserBundle ApiIndex action
 * with the upgrade banner when the `rest_api_access` feature is disabled, and
 * lets the API token UI render normally when the feature is enabled or in
 * self-hosted mode.
 */
#[Group('functional')]
final class ApiTokenCreateGateTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private const string GATED_HEADLINE = 'This feature requires an upgrade';

    public function testGatedIndexRendersUpgradeBanner(): void
    {
        $client = $this->bootClient($this->buildFeatureGate(['rest_api_access' => false]));

        $client->request(Request::METHOD_GET, '/profile/api');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()
            ->getContent();
        self::assertStringContainsString(self::GATED_HEADLINE, $body);
        // Confirm the live component (and its create-button) is NOT in the gated page
        self::assertStringNotContainsString('id="api-token-', $body);
    }

    public function testUngatedIndexRendersTokenList(): void
    {
        $client = $this->bootClient($this->buildFeatureGate(['rest_api_access' => true]));

        $client->request(Request::METHOD_GET, '/profile/api');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()
            ->getContent();
        self::assertStringNotContainsString(self::GATED_HEADLINE, $body);
    }

    public function testSelfHostedIndexRendersTokenList(): void
    {
        $client = $this->bootClient();

        $container = self::getContainer();

        $providerId = 'test.' . UpgradePromptProvider::class;
        self::assertTrue($container->has($providerId));

        if (($_ENV['AUGIAS_PLATFORM'] ?? $_SERVER['AUGIAS_PLATFORM'] ?? null) !== 'saas') {
            self::assertInstanceOf(NullUpgradePromptProvider::class, $container->get($providerId));
        }

        $client->request(Request::METHOD_GET, '/profile/api');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::GATED_HEADLINE, (string) $client->getResponse()->getContent());
    }

    /**
     * @param array<string, bool> $overrides
     */
    private function buildFeatureGate(array $overrides): FeatureGate
    {
        $featureGate = $this->createStub(FeatureGate::class);
        $featureGate->method('isEnabled')
            ->willReturnCallback(static fn (string $key): bool => $overrides[$key] ?? true);

        return $featureGate;
    }

    private function bootClient(?FeatureGate $featureGate = null): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->disableReboot();

        // This test has failed intermittently in CI with a bare 500, and the only
        // evidence left behind was the rendered error page — which says "Internal
        // server error" and nothing else. Letting the kernel rethrow means the next
        // occurrence carries the exception and its stack trace into the test output
        // instead. It changes nothing while the tests pass: all three assert a
        // successful response, so nothing here expects an error page.
        $client->catchExceptions(false);

        if ($featureGate instanceof FeatureGate) {
            self::getContainer()->set(FeatureGate::class, $featureGate);
        }

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $client->loginUser($user);

        return $client;
    }
}
