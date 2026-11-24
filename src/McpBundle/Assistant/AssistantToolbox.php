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
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use JsonException;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use function array_diff;
use function array_map;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;

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
        'create_quote', 'update_quote', 'clone_quote', 'convert_quote_to_invoice', 'create_deposit_invoice',
        'create_invoice', 'update_invoice', 'clone_invoice',
        'create_product', 'update_product', 'create_category', 'update_category',
        'create_resource', 'update_resource', 'add_contact', 'add_client_address', 'update_client_address', 'add_client_tax_identifier',
        'send_quote', 'send_invoice', 'send_invoice_reminder', 'record_payment',
    ];

    /** What create_resource and update_resource may touch here. */
    private const array RESOURCES = ['client', 'contact'];

    /** Put before every result, next to the figures it is about. */
    public const string AMOUNTS_NOTE = 'Montants en centimes (unité mineure) : divise par 100 avant de les donner, 8997 = 89,97 €.';

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
    ) {
        $this->handler = new ReferenceHandler($tools);
    }

    /**
     * The tools offered to the model, as an OpenAI-compatible `tools` list.
     *
     * @return list<array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->offeredTools() as $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => (string) $tool->description . ($this->isWrite($tool->name) ? ' (Writes: the person confirms it before it runs.)' : ''),
                    'parameters' => $tool->inputSchema,
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
     * Why a write the model proposed is refused before it is even shown, if it is.
     *
     * @param array<string, mixed> $arguments
     */
    public function refusal(string $name, array $arguments): ?string
    {
        if (in_array($name, ['create_resource', 'update_resource'], true) && ! in_array($arguments['resource'] ?? null, self::RESOURCES, true)) {
            return 'here the assistant only creates and changes clients and contacts.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $scopes
     */
    private function execute(string $name, array $arguments, array $scopes): string
    {
        $request = $this->requestStack->getMainRequest();
        $request?->attributes->set(McpOAuthAuthenticator::ATTR_SCOPES, $scopes);

        try {
            // The SDK reads the MCP session from the arguments; there is none here.
            $result = $this->handler->handle($this->registry->getTool($name), [...$arguments, '_session' => null]);
            $encoded = is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // The tools give amounts in the minor unit; a small model reads
            // "8997" as 8 997 € unless reminded next to the figures.
            return self::AMOUNTS_NOTE . "\n" . $encoded;
        } catch (ToolCallException $e) {
            return 'Error: ' . $e->getMessage();
        } catch (JsonException) {
            return 'Error: the result could not be encoded.';
        } catch (Throwable) {
            return 'Error: the tool failed.';
        } finally {
            $request?->attributes->remove(McpOAuthAuthenticator::ATTR_SCOPES);
        }
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
