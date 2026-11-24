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
use Augias\McpBundle\Action\AssistantMessagesAction;
use Augias\McpBundle\Assistant\AssistantClient;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use function json_decode;

/**
 * The side panel: on every page once a model is configured, and the
 * conversation it shows, the same as the page's.
 */
#[CoversClass(AssistantMessagesAction::class)]
#[Group('functional')]
final class AssistantPanelTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    public function testNoPanelUntilAModelIsConfigured(): void
    {
        $client = $this->client();

        $client->request('GET', '/clients/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#assistant-panel');

        $client->request('GET', '/assistant/messages');
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePanelIsOnEveryPage(): void
    {
        $client = $this->client();
        $this->configure(['Aucune facture en retard.']);

        $client->request('GET', '/clients/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#assistant-panel[data-controller="assistant-panel"]');
        self::assertSelectorExists('header [data-assistant-toggle]');
    }

    public function testAQuestionAskedFromThePanelIsAnsweredAndKept(): void
    {
        $client = $this->client();
        $this->configure(['Aucune facture en retard.']);

        $crawler = $client->request('GET', '/clients/');
        $token = (string) $crawler->filter('#assistant-panel')->attr('data-assistant-panel-token-value');

        $client->request('POST', '/assistant/messages', ['question' => 'Des factures en retard ?', '_token' => $token]);

        self::assertResponseIsSuccessful();
        $state = $this->state($client);
        self::assertFalse($state['pending']);
        self::assertSame([
            ['role' => 'user', 'content' => 'Des factures en retard ?'],
            ['role' => 'assistant', 'content' => 'Aucune facture en retard.'],
        ], $state['messages']);

        // Another page, the same conversation; and the page shows it too.
        $client->request('GET', '/assistant/messages');
        self::assertCount(2, $this->state($client)['messages']);

        $client->request('GET', '/assistant');
        self::assertSelectorTextContains('#assistant-conversation', 'Aucune facture en retard.');

        $client->request('POST', '/assistant/messages', ['reset' => '1', '_token' => $token]);
        self::assertSame([], $this->state($client)['messages']);
    }

    public function testAQuestionWithoutAValidTokenIsNotAsked(): void
    {
        $client = $this->client();
        $this->configure([]);

        $client->request('POST', '/assistant/messages', ['question' => 'Des factures en retard ?', '_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->state($client)['messages']);
    }

    public function testAModelThatDoesNotAnswerGivesTheQuestionBack(): void
    {
        $client = $this->client();
        $this->configure([new JsonMockResponse([], ['http_code' => 503])]);

        $crawler = $client->request('GET', '/clients/');
        $token = (string) $crawler->filter('#assistant-panel')->attr('data-assistant-panel-token-value');

        $client->request('POST', '/assistant/messages', ['question' => 'Des factures en retard ?', '_token' => $token]);

        self::assertResponseStatusCodeSame(503);
        $state = $this->state($client);
        self::assertSame([], $state['messages']);
        self::assertFalse($state['pending']);
        self::assertNotNull($state['error']);
    }

    /**
     * @param list<string|JsonMockResponse> $answers
     */
    private function configure(array $answers): void
    {
        $responses = [];
        foreach ($answers as $answer) {
            $responses[] = $answer instanceof JsonMockResponse ? $answer : new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant', 'content' => $answer]]]]);
        }

        self::getContainer()->set(AssistantClient::class, new AssistantClient(new MockHttpClient($responses), 'https://ai.example/v1', 'key', 'mistral-small'));
    }

    /**
     * @return array{messages: list<array{role: string, content: string}>, pending: bool, error: ?string}
     */
    private function state(KernelBrowser $client): array
    {
        /** @var array{messages: list<array{role: string, content: string}>, pending: bool, error: ?string} */
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
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
