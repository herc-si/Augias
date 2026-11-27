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

namespace Augias\McpBundle\Assistant;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use Augias\InvoiceBundle\Mcp\InvoiceWriteTools;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\Tool\ResourceWriteTools;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use Augias\QuoteBundle\Mcp\QuoteWriteTools;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use function array_diff;
use function array_diff_key;
use function array_filter;
use function array_intersect;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function str_contains;
use function str_ends_with;

/**
 * The MCP tools the built-in assistant may call: those that read, run as the
 * model asks, and a chosen few that write, run only once the person has
 * confirmed them (Assistant, AssistantMessagesAction).
 *
 * The assistant is the MCP server's own tools, called from inside Augias
 * rather than by an outside client (Rémi, 23/11/2026). The scopes it runs
 * with are set on the request, where McpScopeGuard reads them, and the
 * person's role still applies on top: an accountant's assistant writes
 * nothing, confirmed or not.
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantTest
 */
final class AssistantToolbox
{
    /** What the assistant may do unasked: read, the figures included. */
    public const array SCOPES = [McpScope::Read->value, McpScope::Finance->value];

    /**
     * The tools that write and may be proposed (Rémi, 24/11/2026: drafts,
     * catalogue, clients and contacts, sending and payments). Deleting,
     * credit notes, purchases, taxes and the books stay out of reach.
     */
    public const array WRITE_TOOLS = [
        'create_quote', 'update_quote', 'clone_quote', 'convert_quote_to_invoice', 'create_deposit_invoice', 'apply_quote_transition',
        'create_invoice', 'update_invoice', 'clone_invoice',
        'create_product', 'update_product', 'create_category', 'update_category',
        'create_resource', 'update_resource', 'add_contact', 'add_client_address', 'update_client_address', 'add_client_tax_identifier',
        'send_quote', 'send_invoice', 'send_invoice_reminder', 'record_payment',
    ];

    /**
     * The writes that go out of Augias — an e-mail to a client, a payment
     * recorded — and are confirmed each on their own, even within a request
     * the person has already confirmed.
     */
    public const array CONFIRMED_EACH = ['send_quote', 'send_invoice', 'send_invoice_reminder', 'record_payment'];

    /** The quote transitions offered here. */
    private const array QUOTE_TRANSITIONS = ['cancel', 'reopen'];

    /** What create_resource and update_resource may touch here. */
    private const array RESOURCES = ['client', 'contact'];

    /** Put before every result, next to the figures it is about. */
    public const string AMOUNTS_NOTE = 'Montants déjà écrits en euros (ou dans leur devise) : recopie-les tels quels. Pour écrire, donne-les en centimes : 100,00 € = 10000.';

    /**
     * The fields that hold an amount in the minor unit, by the words of
     * their name; ids, dates, counts and rates are not.
     */
    private const string AMOUNT_FIELD = '/(^|_)(total|amount|balance|price|outstanding|turnover|money|due|net|remaining|tax|debit|credit|ceiling|paid)(_|$)/';

    private const string NOT_AMOUNT_FIELD = '/(_id|(?<!_to)_date|_on|_at|_number|_count|_rate|_percent|_percentage)$/';

    private readonly ReferenceHandler $handler;

    private bool $built = false;

    public function __construct(
        #[Autowire(service: 'mcp.registry')]
        private readonly Registry $registry,
        #[Autowire(service: 'mcp.server.builder')]
        private readonly Builder $builder,
        #[AutowireLocator('mcp.tool')]
        ContainerInterface $tools,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly ResourceWriteTools $resources,
        private readonly QuoteWriteTools $quotes,
        private readonly InvoiceWriteTools $invoices,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->handler = new ReferenceHandler($tools);
    }

    /**
     * The tools a request is given, by family: all 49 described cost some
     * 7 500 tokens on every turn, and a million tokens went in an afternoon
     * of tries (Rémi, 26/11/2026). The core is always there; a family comes
     * with the words of the request, or when the model asks for it
     * (MORE_TOOLS). A tool not in any family is in « finance ».
     *
     * @var array<string, list<string>>
     */
    public const array FAMILIES = [
        'core' => ['search', 'get_resource', 'list_resource', 'get_company_info', 'get_document_links', 'list_workflow_transitions'],
        'quotes' => ['list_quotes_by_status', 'create_quote', 'update_quote', 'apply_quote_transition', 'clone_quote', 'convert_quote_to_invoice', 'create_deposit_invoice', 'send_quote', 'search_products', 'list_tax_rates'],
        'invoices' => ['list_invoices_by_status', 'list_overdue_invoices', 'list_recurring_invoices', 'create_invoice', 'update_invoice', 'clone_invoice', 'create_deposit_invoice', 'send_invoice', 'send_invoice_reminder', 'record_payment', 'list_payment_methods', 'search_products', 'list_tax_rates'],
        // What a document for a client not yet there needs: the rest of « clients » only when asked.
        'new_client' => ['create_resource', 'add_contact', 'search_company_register'],
        'clients' => ['search_company_register', 'create_resource', 'update_resource', 'add_contact', 'add_client_address', 'update_client_address', 'add_client_tax_identifier', 'list_client_tax_identifiers', 'get_client_summary', 'get_client_statement'],
        'catalogue' => ['search_products', 'create_product', 'update_product', 'create_category', 'update_category', 'list_categories', 'list_tax_rates'],
        'finance' => [],
    ];

    /** The words that bring a family, lower-cased, matched within the words of the request. */
    private const array FAMILY_WORDS = [
        'quotes' => ['devis', 'quote', 'estimat', 'proposition'],
        'invoices' => ['factur', 'invoice', 'acompte', 'relanc', 'paiement', 'payé', 'payée', 'paye', 'réglé', 'encaiss', 'impayé', 'retard', 'overdue', 'payment', 'paid', 'reminder'],
        'clients' => ['client', 'contact', 'adresse', 'siret', 'siren', 'e-mail', 'email', 'customer', 'address', 'annuaire', 'registre', 'sirene', 'register', 'entreprise', 'société', 'societe', 'commune', 'mairie', 'association'],
        'catalogue' => ['produit', 'catalogue', 'article', 'prestation', 'tarif', 'catégorie', 'product', 'catalog', 'category'],
        'finance' => ['chiffre', 'compta', 'tva', 'déclar', 'urssaf', 'banque', 'bancaire', 'trésorerie', 'achat', 'fournisseur', 'revenu', 'livre', 'journal', 'reporting', 'reçue', 'turnover', 'revenue', 'bank', 'supplier', 'purchase', 'vat', 'accounting', 'ledger'],
    ];

    /** The tool the model asks for more families with. */
    public const string MORE_TOOLS = 'more_tools';

    /**
     * The families the words of a request call for. None recognised: all of
     * them, rather than an assistant that cannot do what it is asked.
     *
     * @return list<string>
     */
    public static function familiesFor(string $text): array
    {
        $text = mb_strtolower($text);
        $families = [];

        foreach (self::FAMILY_WORDS as $family => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $families[] = $family;

                    break;
                }
            }
        }

        if ($families === []) {
            return array_keys(self::FAMILIES);
        }

        // A document is for a client, who may not exist yet.
        if (array_intersect($families, ['quotes', 'invoices']) !== [] && ! in_array('clients', $families, true)) {
            $families[] = 'new_client';
        }

        return ['core', ...$families];
    }

    /**
     * The family a tool is in, the first one listed.
     */
    public static function familyOf(string $name): string
    {
        foreach (self::FAMILIES as $family => $tools) {
            if (in_array($name, $tools, true)) {
                return $family;
            }
        }

        return $name === self::MORE_TOOLS ? 'core' : 'finance';
    }

    /**
     * The tools offered to the model, as an OpenAI-compatible `tools` list:
     * those of the families given (all when none is), and the one to ask
     * for the others.
     *
     * @param list<string>|null $families
     *
     * @return list<array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function definitions(?array $families = null): array
    {
        $definitions = [];

        foreach ($this->offeredTools() as $tool) {
            if ($families !== null && ! in_array(self::familyOf($tool->name), $families, true) && array_filter($families, static fn (string $family): bool => in_array($tool->name, self::FAMILIES[$family] ?? [], true)) === []) {
                continue;
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => (string) $tool->description . ($this->isWrite($tool->name) ? ' (Writes: the person confirms it before it runs.)' : ''),
                    'parameters' => $tool->inputSchema,
                ],
            ];
        }

        $missing = array_values(array_diff(array_keys(self::FAMILIES), $families ?? array_keys(self::FAMILIES)));

        if ($missing !== []) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => self::MORE_TOOLS,
                    'description' => 'Adds the tools of other families for this request: quotes, invoices (and payments, reminders), clients (and contacts, addresses, the French companies register; new_client only creates one), catalogue (products, categories), finance (turnover, books, VAT, bank, purchases, received invoices).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['families' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $missing]]],
                        'required' => ['families'],
                    ],
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Whether the tool is one that writes and may be proposed.
     */
    public function isWrite(string $name): bool
    {
        return in_array($name, self::WRITE_TOOLS, true) && $this->isOffered($name);
    }

    /**
     * Whether, once the person has confirmed a request, this write runs
     * without being asked again: one confirmation for the request (Rémi,
     * 26/11/2026: thirty to make a quote), sending and payments apart.
     */
    public function runsOnceApproved(string $name): bool
    {
        return $this->isWrite($name) && ! in_array($name, self::CONFIRMED_EACH, true);
    }

    /**
     * Runs one tool that only reads, as the model asked, and gives back what
     * to show it: the result, or why it could not run. Never throws.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments): string
    {
        if ($this->isWrite($name)) {
            return 'Error: "' . $name . '" writes and is only run once the person has confirmed it.';
        }

        if (! $this->isOffered($name)) {
            return 'Error: no read-only tool is called "' . $name . '". The assistant cannot change anything.';
        }

        return $this->execute($name, $arguments, self::SCOPES);
    }

    /**
     * Runs a tool that writes, once the person has confirmed it. Never throws.
     *
     * @param array<string, mixed> $arguments
     */
    public function run(string $name, array $arguments): string
    {
        if (! $this->isWrite($name)) {
            return 'Error: "' . $name . '" cannot be run by the assistant.';
        }

        $refusal = $this->refusal($name, $arguments);

        if ($refusal !== null) {
            return 'Error: ' . $refusal;
        }

        return $this->execute($name, $arguments, [...self::SCOPES, McpScope::Write->value]);
    }

    /**
     * The arguments of a change the model asks for, as it will be shown and
     * made: a client named and nothing more is a private individual, not a
     * business (which needs its SIRET) — the model asked three questions
     * about a client before proposing it (Rémi, 26/11/2026).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function prepared(string $name, array $arguments): array
    {
        // Amounts copied as the model read them, back in cents for the check and the card.
        $arguments = self::minorAmounts($arguments);

        try {
            $arguments = $this->normalised($this->registry->getTool($name)->tool->inputSchema, $arguments);
        } catch (Throwable) {
            // An unknown tool: the call says so.
        }

        if ($name !== 'create_resource' || ! is_string($arguments['resource'] ?? null)) {
            return $arguments;
        }

        $data = $this->decodedArguments(['properties' => ['data' => ['type' => 'object']]], ['data' => $arguments['data'] ?? []])['data'];

        return ['data' => self::clientDefaults($arguments['resource'], is_array($data) ? $data : [])] + $arguments;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function clientDefaults(string $resource, array $data): array
    {
        if ($resource !== 'client' || \array_key_exists('is_company', $data)) {
            return $data;
        }

        $identified = array_filter([$data['siret'] ?? null, $data['siren'] ?? null, $data['vat_number'] ?? null], static fn (mixed $value): bool => is_string($value) && $value !== '');

        return ['is_company' => $identified !== []] + $data;
    }

    /**
     * Why a write the model proposed is refused before it is even shown, if it is.
     *
     * @param array<string, mixed> $arguments
     */
    public function refusal(string $name, array $arguments): ?string
    {
        if (in_array($name, ['create_resource', 'update_resource'], true) && ! in_array($arguments['resource'] ?? null, self::RESOURCES, true)) {
            return 'here the assistant only creates and changes clients and contacts.';
        }

        // Checked before the person is asked: they confirm only what will go through.
        if ($name === 'create_resource' && is_string($arguments['resource'] ?? null)) {
            $data = $this->decodedArguments(['properties' => ['data' => ['type' => 'object']]], ['data' => $arguments['data'] ?? []])['data'];

            return $this->resources->creationProblem($arguments['resource'], self::clientDefaults($arguments['resource'], is_array($data) ? $data : []));
        }

        // Only what can be undone: a cancelled quote is reopened as a draft
        // (Rémi, 26/11/2026: « annule les devis de Jean Toto »). Sending
        // goes through send_quote, the client's answer stays the person's.
        if ($name === 'apply_quote_transition' && ! in_array($arguments['transition'] ?? null, self::QUOTE_TRANSITIONS, true)) {
            return 'here the assistant only cancels a quote ("cancel") or reopens a cancelled one ("reopen").';
        }

        // Someone to send it to, before the person confirms the sending.
        if ($name === 'send_quote' && is_string($arguments['quote_id'] ?? null)) {
            return $this->quotes->sendingProblem($arguments['quote_id']);
        }

        if (in_array($name, ['send_invoice', 'send_invoice_reminder'], true) && is_string($arguments['invoice_id'] ?? null)) {
            return $this->invoices->sendingProblem($arguments['invoice_id']);
        }

        if ($name === 'create_quote' || $name === 'create_invoice') {
            $decoded = $this->decodedArguments(['properties' => ['lines' => ['type' => 'array'], 'contact_ids' => ['type' => 'array'], 'invoice_taxes' => ['type' => 'array']]], $arguments);

            return $name === 'create_quote' ? $this->quotes->creationProblem($decoded) : $this->invoices->creationProblem($decoded);
        }

        return null;
    }

    /**
     * Every amount of a result written as the person reads it: "10000" in
     * a document in EUR is « 100,00 € ». The currency is the nearest one
     * given beside it or above it. Amounts come as strings of digits, in a
     * field named for one (total, price…) or listed under one (by_activity
     * → services_bnc); integers are counts and are left.
     */
    public static function readableAmounts(mixed $value, string $currency, ?string $key = null, bool $underAmount = false): mixed
    {
        if (is_array($value)) {
            $currency = is_string($value['currency'] ?? null) && $value['currency'] !== '' ? $value['currency'] : $currency;
            $amounts = $key !== null && (self::isAmountField($key) || str_starts_with($key, 'by_'));
            $readable = [];

            foreach ($value as $field => $item) {
                $readable[$field] = is_string($field)
                    ? self::readableAmounts($item, $currency, $field, $amounts)
                    : self::readableAmounts($item, $currency, $key, $underAmount);
            }

            return $readable;
        }

        if ($key === null || ! is_string($value) || preg_match('/^-?\d+$/', $value) !== 1 || preg_match(self::NOT_AMOUNT_FIELD, $key) === 1 || ! ($underAmount || self::isAmountField($key))) {
            return $value;
        }

        $negative = str_starts_with($value, '-');
        $digits = str_pad(ltrim($value, '-'), 3, '0', STR_PAD_LEFT);
        $units = number_format((int) substr($digits, 0, -2), 0, '', "\u{202f}");

        return ($negative ? '-' : '') . $units . ',' . substr($digits, -2) . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    /**
     * The other way: an amount the model copied as it read it (« 100,00 € »)
     * goes back to the tool in the minor unit, 10000.
     */
    public static function minorAmounts(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::minorAmounts(...), $value);
        }

        if (! is_string($value) || preg_match('/^(-?)([\d\s\x{202f}\x{a0}.]+),(\d{2})\s*(€|[A-Z]{3})$/u', trim($value), $match) !== 1) {
            return $value;
        }

        return (int) ($match[1] . preg_replace('/\D/', '', $match[2]) . $match[3]);
    }

    private static function isAmountField(string $key): bool
    {
        return preg_match(self::AMOUNT_FIELD, $key) === 1 && preg_match(self::NOT_AMOUNT_FIELD, $key) !== 1;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $scopes
     */
    private function execute(string $name, array $arguments, array $scopes): string
    {
        $request = $this->requestStack->getMainRequest();
        $request?->attributes->set(McpOAuthAuthenticator::ATTR_SCOPES, $scopes);

        if (! $this->entityManager->isOpen()) {
            // An earlier tool of the request was refused by the database: nothing more can be saved or read safely.
            return 'Error: an earlier change of this request failed, so nothing more can be done now. Tell the person what was made and ask them to ask again for the rest.';
        }

        try {
            $tool = $this->registry->getTool($name);
            // The SDK reads the MCP session from the arguments; there is none here.
            $result = $this->handler->handle($tool, [...$this->normalised($tool->tool->inputSchema, self::minorAmounts($arguments)), '_session' => null]);

            if ($result === [] || $result === null) {
                return 'Nothing found. Try one distinctive word (a name, a number), or a list_* tool.';
            }

            // The tools give amounts in the minor unit. Reminded to divide by
            // 100, the model still read "8997" as 8 997 € (24/11/2026), then
            // a 100 € invoice as 10 € (27/11/2026): it is given them written.
            $encoded = is_string($result) ? $result : json_encode(self::readableAmounts($result, 'EUR'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return self::AMOUNTS_NOTE . "\n" . $encoded;
        } catch (ToolCallException $e) {
            return 'Error: ' . $e->getMessage();
        } catch (JsonException) {
            return 'Error: the result could not be encoded.';
        } catch (RegistryException $e) {
            // The arguments against the tool's parameters: « Missing required argument `changes` ».
            return 'Error: ' . $e->getMessage();
        } catch (Throwable $e) {
            // Never shown to the model, which only learns the arguments did not fit.
            $this->logger->warning('An assistant tool failed.', ['tool' => $name, 'exception' => $e]);

            return 'Error: the tool failed with these arguments. Check them against the tool\'s parameters: ids are the ULIDs that search and the list_* tools return, and an object is given as an object.';
        } finally {
            $request?->attributes->remove(McpOAuthAuthenticator::ATTR_SCOPES);
        }
    }

    /**
     * The arguments put the way the tool takes them, where there is only one
     * way to read them: the fields of an update given beside the object
     * that should hold them (`{"quote_id": …, "lines": […]}` for
     * `{"quote_id": …, "changes": {"lines": […]}}`), `id` for the one id
     * the tool asks for, objects sent as JSON text. Mistral did all three,
     * and was refused a dozen times in a row (26/11/2026).
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function normalised(array $schema, array $arguments): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        // « id » for the one *_id the tool requires.
        $ids = array_values(array_filter($required, static fn (mixed $name): bool => is_string($name) && str_ends_with($name, '_id')));

        if (\count($ids) === 1 && ! isset($properties['id']) && \array_key_exists('id', $arguments) && ! \array_key_exists($ids[0], $arguments)) {
            $arguments[$ids[0]] = $arguments['id'];
            unset($arguments['id']);
        }

        $arguments = $this->decodedArguments($schema, $arguments);

        // Loose fields into the one object that holds the changes.
        foreach (['changes', 'data'] as $holder) {
            if (! isset($properties[$holder]) || ($properties[$holder]['type'] ?? null) !== 'object' || isset($arguments[$holder])) {
                continue;
            }

            $loose = array_diff_key($arguments, $properties);

            if ($loose !== []) {
                $arguments = [...array_intersect_key($arguments, $properties), $holder => $loose];
            }
        }

        return $arguments;
    }

    /**
     * The arguments as the tool takes them: a small model often sends an
     * object or a list as JSON text (`"changes": "{\"subject\": …}"`), which
     * the tool's type check would refuse.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function decodedArguments(array $schema, array $arguments): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($arguments as $key => $value) {
            $type = $properties[$key]['type'] ?? null;
            $types = is_array($type) ? $type : [$type];

            if (! is_string($value) || (! in_array('object', $types, true) && ! in_array('array', $types, true))) {
                continue;
            }

            try {
                $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                $arguments[$key] = $decoded;
            }
        }

        return $arguments;
    }

    /**
     * @return list<Tool>
     */
    private function offeredTools(): array
    {
        $tools = [];
        foreach ($this->allTools() as $tool) {
            if ($this->isOffered($tool->name)) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @return list<Tool>
     */
    private function allTools(): array
    {
        // The loaders fill the registry when the server is built: once.
        if (! $this->built) {
            $this->builder->build();
            $this->built = true;
        }

        $tools = [];
        foreach ($this->registry->getTools() as $tool) {
            if ($tool instanceof Tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    private function isOffered(string $name): bool
    {
        $this->allTools();

        try {
            $handler = $this->registry->getTool($name)->handler;
        } catch (Throwable) {
            return false;
        }

        if (! is_array($handler) || ! is_string($handler[0] ?? null) || ! is_string($handler[1] ?? null)) {
            return false;
        }

        $scopes = array_map(
            static fn (ReflectionAttribute $attribute): McpScope => $attribute->newInstance()->scope,
            new ReflectionMethod($handler[0], $handler[1])->getAttributes(McpScopeRequired::class),
        );

        // A tool that says nothing is not trusted to only read.
        if ($scopes === []) {
            return false;
        }

        // A tool that writes, the books included, declares Write among its scopes.
        if (in_array(McpScope::Write, $scopes, true)) {
            return in_array($name, self::WRITE_TOOLS, true);
        }

        return array_diff(array_map(static fn (McpScope $scope): string => $scope->value, $scopes), self::SCOPES) === [];
    }
}
