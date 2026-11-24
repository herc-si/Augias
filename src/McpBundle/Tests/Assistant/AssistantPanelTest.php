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

use Augias\CatalogBundle\Entity\Product;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\McpBundle\Action\AssistantMessagesAction;
use Augias\McpBundle\Action\AssistantTranscribeAction;
use Augias\McpBundle\Assistant\AssistantClient;
use Augias\McpBundle\Assistant\AssistantTranscriber;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use function array_key_last;
use function file_put_contents;
use function is_array;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;

/**
 * The side panel: on every page once a model is configured, and the
 * conversation it shows, the same as the page's.
 */
#[CoversClass(AssistantMessagesAction::class)]
#[CoversClass(AssistantTranscribeAction::class)]
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

        // Another page, the same conversation.
        $client->request('GET', '/assistant/messages');
        self::assertCount(2, $this->state($client)['messages']);

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

    public function testAChangeIsShownThenMadeOnlyOnceConfirmed(): void
    {
        $client = $this->client();
        $this->configure([
            ['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'create_product', 'arguments' => '{"name":"Conseil","sale_price":8000,"unit":"hour"}']]]],
            '"Conseil" is in the catalogue.',
        ]);
        $token = $this->token($client);

        $client->request('POST', '/assistant/messages', ['question' => 'Add Conseil at 80 an hour', '_token' => $token]);

        self::assertResponseIsSuccessful();
        $state = $this->state($client);
        self::assertNotNull($state['action']);
        self::assertSame('Add to the catalogue', $state['action']['title']);
        self::assertContains(['label' => 'Sale price excl. tax', 'value' => '80.00'], $state['action']['details']);
        self::assertSame(0, $this->products('Conseil'));

        // A confirmation naming another proposal makes nothing.
        $client->request('POST', '/assistant/messages', ['confirm' => 'someotherid', '_token' => $token]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->products('Conseil'));

        $client->request('POST', '/assistant/messages', ['confirm' => $state['action']['id'], '_token' => $token]);

        self::assertResponseIsSuccessful();
        $state = $this->state($client);
        self::assertNull($state['action']);
        self::assertSame(1, $this->products('Conseil'));
        self::assertSame('"Conseil" is in the catalogue.', $state['messages'][array_key_last($state['messages'])]['content']);
    }

    public function testACancelledChangeIsNotMade(): void
    {
        $client = $this->client();
        $this->configure([
            ['content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'create_product', 'arguments' => '{"name":"Conseil","sale_price":8000}']]]],
        ]);
        $token = $this->token($client);

        $client->request('POST', '/assistant/messages', ['question' => 'Add Conseil at 80', '_token' => $token]);
        $id = $this->state($client)['action']['id'] ?? '';

        $client->request('POST', '/assistant/messages', ['cancel' => $id, '_token' => $token]);

        self::assertResponseIsSuccessful();
        $state = $this->state($client);
        self::assertNull($state['action']);
        self::assertSame('Change cancelled: nothing was modified.', $state['messages'][array_key_last($state['messages'])]['content']);
        self::assertSame(0, $this->products('Conseil'));

        // Settled once: confirming it afterwards makes nothing either.
        $client->request('POST', '/assistant/messages', ['confirm' => $id, '_token' => $token]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->products('Conseil'));
    }

    public function testWhatIsSaidIsWrittenOut(): void
    {
        $client = $this->client();
        $this->configure([]);
        self::getContainer()->set(AssistantTranscriber::class, new AssistantTranscriber(
            new MockHttpClient([
                new JsonMockResponse(['batch_id' => 'b1']),
                function (string $method, string $url): JsonMockResponse {
                    self::assertSame('https://ai.example/1/ai/7/results/b1', $url);

                    return new JsonMockResponse(['status' => 'pending', 'data' => null]);
                },
                new JsonMockResponse(['status' => 'success', 'data' => '{"text":" Quel est mon chiffre d\'affaires ? "}']),
            ]),
            new MockClock(),
            'https://ai.example/1/ai/7/openai/audio/transcriptions',
            'key',
            'whisper',
        ));

        $crawler = $client->request('GET', '/clients/');
        self::assertSelectorExists('#assistant-panel[data-assistant-panel-transcribe-url-value]');
        $token = (string) $crawler->filter('#assistant-panel')->attr('data-assistant-panel-token-value');

        $path = (string) tempnam(sys_get_temp_dir(), 'voice');
        file_put_contents($path, 'not really opus');
        $client->request('POST', '/assistant/transcribe', ['_token' => $token], ['audio' => new UploadedFile($path, 'voice', 'audio/webm;codecs=opus', null, true)]);

        self::assertResponseIsSuccessful();
        self::assertSame(['text' => 'Quel est mon chiffre d\'affaires ?'], json_decode((string) $client->getResponse()->getContent(), true));

        // Anything but audio is not passed on.
        file_put_contents($path, '<?php');
        $client->request('POST', '/assistant/transcribe', ['_token' => $token], ['audio' => new UploadedFile($path, 'voice.php', 'application/x-php', null, true)]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testNoMicrophoneUntilTranscriptionIsConfigured(): void
    {
        $client = $this->client();
        $this->configure([]);

        $client->request('GET', '/clients/');
        self::assertSelectorNotExists('#assistant-panel[data-assistant-panel-transcribe-url-value]');

        $client->request('POST', '/assistant/transcribe');
        self::assertResponseStatusCodeSame(404);
    }

    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/clients/');

        return (string) $crawler->filter('#assistant-panel')->attr('data-assistant-panel-token-value');
    }

    private function products(string $name): int
    {
        return self::getContainer()->get('doctrine')->getManager()->getRepository(Product::class)->count(['name' => $name]);
    }

    /**
     * @param list<string|array<string, mixed>|JsonMockResponse> $answers a reply, the model's message, or the response itself
     */
    private function configure(array $answers): void
    {
        $responses = [];
        foreach ($answers as $answer) {
            $responses[] = match (true) {
                $answer instanceof JsonMockResponse => $answer,
                is_array($answer) => new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant'] + $answer]]]),
                default => new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant', 'content' => $answer]]]]),
            };
        }

        self::getContainer()->set(AssistantClient::class, new AssistantClient(new MockHttpClient($responses), 'https://ai.example/v1', 'key', 'mistral-small'));
    }

    /**
     * @return array{messages: list<array{role: string, content: string}>, pending: bool, action: ?array{id: string, title: string, details: list<array{label: string, value: string}>}, error: ?string}
     */
    private function state(KernelBrowser $client): array
    {
        /** @var array{messages: list<array{role: string, content: string}>, pending: bool, action: ?array{id: string, title: string, details: list<array{label: string, value: string}>}, error: ?string} */
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
