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

use function is_array;
use function is_string;

/**
 * A tool that writes, asked for by the model and waiting for the person to
 * confirm it, with all the model needs to carry on once it is settled: the
 * conversation so far and the results of the other tools it asked for at
 * the same time (null in place of this one's).
 */
final readonly class AssistantProposal
{
    /**
     * @param array<string, mixed>                                      $arguments
     * @param list<array<string, mixed>>                                $messages  the conversation as sent to the model, the system prompt left out
     * @param list<array{id: string, name: string, content: ?string}>   $results   one per tool asked for in the same turn, in order
     */
    public function __construct(
        public string $callId,
        public string $tool,
        public array $arguments,
        public array $messages,
        public array $results,
    ) {
    }

    /**
     * @return array{callId: string, tool: string, arguments: array<string, mixed>, messages: list<array<string, mixed>>, results: list<array{id: string, name: string, content: ?string}>}
     */
    public function toArray(): array
    {
        return ['callId' => $this->callId, 'tool' => $this->tool, 'arguments' => $this->arguments, 'messages' => $this->messages, 'results' => $this->results];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['callId'] ?? null) || ! is_string($data['tool'] ?? null) || ! is_array($data['arguments'] ?? null) || ! is_array($data['messages'] ?? null) || ! is_array($data['results'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $data['arguments'];
        /** @var list<array<string, mixed>> $messages */
        $messages = $data['messages'];
        /** @var list<array{id: string, name: string, content: ?string}> $results */
        $results = $data['results'];

        return new self($data['callId'], $data['tool'], $arguments, $messages, $results);
    }
}
