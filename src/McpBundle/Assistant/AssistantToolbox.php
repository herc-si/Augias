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
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;

/**
 * The MCP tools the built-in assistant may call: those that only read.
 *
 * The assistant is the MCP server's own tools, called from inside Augias
 * rather than by an outside client (Rémi, 23/11/2026). It reads and never
 * writes: a tool declaring the write scope is neither offered to the model
 * nor run when asked for. The scopes it runs with are set on the request,
 * where McpScopeGuard reads them, and the person's role still applies on top.
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantTest
 */
final class AssistantToolbox
{
    /** What the assistant may do: read, the figures included. */
    public const array SCOPES = [McpScope::Read->value, McpScope::Finance->value];

    private readonly ReferenceHandler $handler;

    private bool $built = false;

    /** Put before every result, next to the figures it is about. */
    public const string AMOUNTS_NOTE = 'Montants en centimes (unité mineure) : divise par 100 avant de les donner, 8997 = 89,97 €.';

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
     * The read-only tools, as an OpenAI-compatible `tools` list.
     *
     * @return list<array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->readOnlyTools() as $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => (string) $tool->description,
                    'parameters' => $tool->inputSchema,
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Runs one tool the model asked for and gives back what to show it: the
     * tool's result, or why it could not run. Never throws.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments): string
    {
        $tool = null;
        foreach ($this->readOnlyTools() as $candidate) {
            if ($candidate->name === $name) {
                $tool = $candidate;
            }
        }

        if (! $tool instanceof Tool) {
            return 'Error: no read-only tool is called "' . $name . '". The assistant cannot change anything.';
        }

        $request = $this->requestStack->getMainRequest();
        $request?->attributes->set(McpOAuthAuthenticator::ATTR_SCOPES, self::SCOPES);

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
    private function readOnlyTools(): array
    {
        // The loaders fill the registry when the server is built: once.
        if (! $this->built) {
            $this->builder->build();
            $this->built = true;
        }

        $tools = [];
        foreach ($this->registry->getTools() as $tool) {
            if ($tool instanceof Tool && $this->isReadOnly($tool->name)) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    private function isReadOnly(string $name): bool
    {
        $handler = $this->registry->getTool($name)->handler;

        if (! is_array($handler) || ! is_string($handler[0] ?? null) || ! is_string($handler[1] ?? null)) {
            return false;
        }

        $attributes = new ReflectionMethod($handler[0], $handler[1])->getAttributes(McpScopeRequired::class);

        // A tool that says nothing is not trusted to only read.
        if ($attributes === []) {
            return false;
        }

        return in_array($attributes[0]->newInstance()->scope, [McpScope::Read, McpScope::Finance], true);
    }
}
