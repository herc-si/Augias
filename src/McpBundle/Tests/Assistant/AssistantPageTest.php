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

namespace Augias\McpBundle\Tests\Assistant;

use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\McpBundle\Action\AssistantAction;
use Augias\McpBundle\Assistant\AssistantClient;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The assistant's page: absent until the model is configured, then a
 * question and its answer.
 */
#[CoversClass(AssistantAction::class)]
#[Group('functional')]
final class AssistantPageTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    public function testThereIsNoAssistantUntilAModelIsConfigured(): void
    {
        $this->client()->request('GET', '/assistant');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAQuestionGetsItsAnswer(): void
    {
        $client = $this->client();
        self::getContainer()->set(AssistantClient::class, new AssistantClient(
            new MockHttpClient([new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Aucune facture en retard.']]]])]),
            'https://ai.example/v1',
            'key',
            'mistral-small',
        ));

        $crawler = $client->request('GET', '/assistant');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#assistant-conversation', 'For example');

        $client->submit($crawler->selectButton('Ask')->form(['question' => 'Des factures en retard ?']));
        self::assertResponseRedirects('/assistant');

        $client->followRedirect();
        self::assertSelectorTextContains('#assistant-conversation', 'Des factures en retard ?');
        self::assertSelectorTextContains('#assistant-conversation', 'Aucune facture en retard.');
    }

    private function client(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $client->loginUser($user);

        return $client;
    }
}
