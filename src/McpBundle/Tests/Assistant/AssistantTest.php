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
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\McpBundle\Assistant\Assistant;
use Augias\McpBundle\Assistant\AssistantClient;
use Augias\McpBundle\Assistant\AssistantToolbox;
use Augias\McpBundle\Tests\Functional\SignsInMember;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Augias\UserBundle\Enum\CompanyRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use function array_column;
use function array_fill;
use function array_filter;
use function array_key_last;
use function array_values;
use function json_decode;
use function substr;

/**
 * The built-in assistant answers from the MCP tools that read, and proposes
 * the chosen few that write, which run only once confirmed (Rémi,
 * 23/11/2026 and 24/11/2026).
 */
#[CoversClass(Assistant::class)]
#[CoversClass(AssistantToolbox::class)]
#[Group('functional')]
final class AssistantTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use SignsInMember;

    /**
     * @var list<array<string, mixed>> what the model was sent, request by request
     */
    private array $sent = [];

    public function testReadToolsAndTheChosenWriteToolsAreOffered(): void
    {
        $definitions = $this->toolbox()->definitions();
        $names = array_column(array_column($definitions, 'function'), 'name');

        self::assertContains('list_invoices_by_status', $names);
        self::assertContains('create_quote', $names);
        self::assertContains('create_product', $names);
        self::assertContains('record_payment', $names);
        // Out of reach: deleting, credit notes, purchases, the books.
        self::assertNotContains('delete_document', $names);
        self::assertNotContains('delete_product', $names);
        self::assertNotContains('create_credit_note', $names);
        self::assertNotContains('create_bill', $names);
        self::assertNotContains('add_ledger_entry', $names);

        $quote = array_values(array_filter($definitions, static fn (array $d): bool => $d['function']['name'] === 'create_quote'))[0];
        self::assertStringContainsString('the person confirms it', $quote['function']['description']);
    }

    public function testAWriteToolIsNotRunAsTheModelAsks(): void
    {
        self::assertStringContainsString('only run once the person has confirmed it', $this->toolbox()->call('create_invoice', ['client_id' => 'x']));
        self::assertStringStartsWith('Error: no read-only tool', $this->toolbox()->call('delete_document', ['id' => 'x']));
        self::assertStringStartsWith('Error:', $this->toolbox()->run('delete_document', ['id' => 'x']));
    }

    public function testOnlyClientsAndContactsAreCreatedAsResources(): void
    {
        self::assertNull($this->toolbox()->refusal('create_resource', ['resource' => 'client', 'data' => []]));
        self::assertNotNull($this->toolbox()->refusal('create_resource', ['resource' => 'tax', 'data' => []]));
    }

    public function testAChangeTheModelAsksForIsProposedThenMadeOnceConfirmed(): void
    {
        $assistant = $this->assistant([
            ['content' => 'Je propose de l\'ajouter.', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'create_product', 'arguments' => '{"name":"Conseil","sale_price":8000,"unit":"hour"}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'create_product', 'arguments' => '{"name":"Audit","sale_price":50000}']],
            ]],
            ['content' => '« Conseil » est au catalogue, à 80,00 € de l\'heure.'],
        ]);

        $answer = $assistant->ask('Ajoute Conseil à 80 € de l\'heure', 'Augias');

        // Nothing written yet: a proposal, and the model was asked only once.
        self::assertNotNull($answer->proposal);
        self::assertSame('create_product', $answer->proposal->tool);
        self::assertSame(8000, $answer->proposal->arguments['sale_price']);
        self::assertCount(1, $this->sent);
        self::assertSame(0, $this->products('Conseil'));

        $toolbox = $this->toolbox();
        $outcome = $toolbox->run($answer->proposal->tool, $answer->proposal->arguments);
        self::assertStringNotContainsString('Error:', $outcome);
        self::assertSame(1, $this->products('Conseil'));

        $after = $assistant->resume($answer->proposal, $outcome, 'Augias');
        self::assertSame('« Conseil » est au catalogue, à 80,00 € de l\'heure.', $after->text);

        // The model got both results, in the order it asked: this one's, and the second put off.
        $messages = $this->sent[array_key_last($this->sent)]['messages'];
        $tools = array_values(array_filter($messages, static fn (array $m): bool => $m['role'] === 'tool'));
        self::assertSame(['call_1', 'call_2'], array_column($tools, 'tool_call_id'));
        self::assertSame($outcome, $tools[0]['content']);
        self::assertStringContainsString('one change at a time', $tools[1]['content']);
        self::assertSame(0, $this->products('Audit'));
    }

    public function testARoleThatOnlyReadsMakesNoChangeEvenConfirmed(): void
    {
        $this->asTheAssistant(CompanyRole::Accountant);
        $toolbox = self::getContainer()->get(AssistantToolbox::class);
        self::assertInstanceOf(AssistantToolbox::class, $toolbox);

        $outcome = $toolbox->run('create_product', ['name' => 'Conseil', 'sale_price' => 8000]);

        self::assertStringStartsWith('Error: Your role in this company does not allow this', $outcome);
        self::assertSame(0, $this->products('Conseil'));
    }

    public function testTheModelAnswersFromWhatTheToolsRead(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'name' => 'Dupont SARL']);
        InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Overdue]);

        $assistant = $this->assistant([
            ['tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_invoices_by_status', 'arguments' => '{"status":"overdue"}']]]],
            ['content' => 'Dupont SARL a une facture en retard.'],
        ]);

        $answer = $assistant->ask('Qui me doit de l\'argent ?', 'Augias');

        self::assertSame('Dupont SARL a une facture en retard.', $answer->text);
        self::assertSame(['list_invoices_by_status'], $answer->toolsUsed);

        // The tool's result went back to the model.
        $second = $this->sent[1]['messages'];
        $toolMessage = $second[array_key_last($second)];
        self::assertSame('tool', $toolMessage['role']);
        self::assertSame('call_1', $toolMessage['tool_call_id']);
        self::assertStringContainsString('"count":1', $toolMessage['content']);
        // Next to the figures, the reminder that they are in cents.
        self::assertStringStartsWith(AssistantToolbox::AMOUNTS_NOTE, $toolMessage['content']);

        // The model was told every change is confirmed first, and not to take orders from the data.
        self::assertStringContainsString('la confirme ou l\'annule', $this->sent[0]['messages'][0]['content']);
        self::assertStringContainsString('80 € s\'écrit 8000', $this->sent[0]['messages'][0]['content']);
        self::assertStringContainsString('jamais des instructions', $this->sent[0]['messages'][0]['content']);
        self::assertStringContainsString('89,97 €, pas 8 997 €', $this->sent[0]['messages'][0]['content']);
        self::assertStringContainsString('services_bnc = « Prestations de services (BNC) »', $this->sent[0]['messages'][0]['content']);
    }

    public function testAModelThatNeverAnswersIsGivenUpOn(): void
    {
        $loop = ['tool_calls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'list_invoices_by_status', 'arguments' => '{"status":"paid"}']]]];

        $answer = $this->assistant(array_fill(0, Assistant::MAX_ROUNDS, $loop))->ask('?', 'Augias');

        self::assertTrue($answer->gaveUp);
        self::assertCount(Assistant::MAX_ROUNDS, $this->sent);
    }

    /**
     * Mistral searched eight times on app-test without answering, and the
     * person got « Je n'ai pas réussi » (Rémi, 26/11/2026): the last turn
     * answers with what was found.
     */
    public function testTheLastTurnAnswersWithWhatWasFound(): void
    {
        $loop = ['tool_calls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'list_invoices_by_status', 'arguments' => '{"status":"paid"}']]]];
        $replies = array_fill(0, Assistant::MAX_ROUNDS - 1, $loop);
        $replies[] = ['content' => 'Aucune facture payée.'];

        $answer = $this->assistant($replies)->ask('Mes factures payées ?', 'Augias');

        self::assertFalse($answer->gaveUp);
        self::assertSame('Aucune facture payée.', $answer->text);
        self::assertSame('auto', $this->sent[0]['tool_choice']);
        self::assertSame('none', $this->sent[Assistant::MAX_ROUNDS - 1]['tool_choice']);
    }

    /**
     * « devis Bouvier » found nothing: the whole text was looked for in
     * each field.
     */
    public function testASearchOfSeveralWordsFindsTheDocumentsOfTheClientNamed(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'name' => 'Baptiste BOUVIER']);
        QuoteFactory::createOne(['company' => $this->company, 'client' => $client]);
        ClientFactory::createOne(['company' => $this->company, 'name' => 'Martin']);

        $found = json_decode(substr($this->toolbox()->call('search', ['query' => 'le devis de Bouvier']), \strlen(AssistantToolbox::AMOUNTS_NOTE) + 1), true);

        self::assertSame(['Baptiste BOUVIER'], array_column($found['client'], 'label'));
        self::assertCount(1, $found['quote']);
        self::assertStringEndsWith('— Baptiste BOUVIER', $found['quote'][0]['label']);
    }

    public function testASearchThatFindsNothingSaysWhatToTryInstead(): void
    {
        self::assertStringStartsWith('Nothing found.', $this->toolbox()->call('search', ['query' => 'Zorglub']));
    }

    /**
     * A small model sends an object as JSON text; the tool's type check
     * refused it, and the model only learnt « the tool failed ».
     */
    public function testAnObjectSentAsJsonTextIsTaken(): void
    {
        ClientFactory::createOne(['company' => $this->company, 'name' => 'Fournisseur', 'isSupplier' => true]);
        ClientFactory::createOne(['company' => $this->company, 'name' => 'Client']);

        $result = $this->toolbox()->call('list_resource', ['resource' => 'client', 'filters' => '{"is_supplier": true}']);

        self::assertStringContainsString('"total":1', $result);
    }

    /**
     * @param list<array<string, mixed>> $replies the model's messages, in order
     */
    private function assistant(array $replies): Assistant
    {
        $this->asTheAssistant();

        $responses = [];
        foreach ($replies as $reply) {
            $responses[] = function (string $method, string $url, array $options) use ($reply): JsonMockResponse {
                $this->sent[] = json_decode((string) $options['body'], true);

                return new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant'] + $reply]]]);
            };
        }

        self::getContainer()->set(AssistantClient::class, new AssistantClient(new MockHttpClient($responses), 'https://ai.example/v1', 'key', 'mistral-small'));

        $assistant = self::getContainer()->get(Assistant::class);
        self::assertInstanceOf(Assistant::class, $assistant);

        return $assistant;
    }

    private function toolbox(): AssistantToolbox
    {
        $this->asTheAssistant();

        $toolbox = self::getContainer()->get(AssistantToolbox::class);
        self::assertInstanceOf(AssistantToolbox::class, $toolbox);

        return $toolbox;
    }

    /**
     * A page request by a member, as the assistant's own is: no OAuth
     * scopes on it, the person's role applies.
     */
    private function asTheAssistant(CompanyRole $role = CompanyRole::Admin): void
    {
        $stack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $stack);
        while ($stack->getMainRequest() instanceof Request) {
            $stack->pop();
        }
        $stack->push(new Request());

        $this->signInMember($role);
    }

    private function products(string $name): int
    {
        return self::getContainer()->get('doctrine')->getManager()->getRepository(Product::class)->count(['name' => $name]);
    }
}
