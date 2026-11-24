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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function is_array;
use function rtrim;

/**
 * The model behind the assistant, reached through an OpenAI-compatible chat
 * completions API: Infomaniak's AI Services by choice (Rémi, 23/11/2026),
 * Mistral or any other such service by configuration alone.
 *
 * AUGIAS_ASSISTANT_BASE_URL is the API's base, up to and without
 * `/chat/completions`; AUGIAS_ASSISTANT_API_KEY its key (from the vault);
 * AUGIAS_ASSISTANT_MODEL the model's name there. Without a base URL and a
 * key, the assistant is not offered at all.
 */
final readonly class AssistantClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'AUGIAS_ASSISTANT_BASE_URL')]
        private string $baseUrl,
        #[Autowire(env: 'AUGIAS_ASSISTANT_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'AUGIAS_ASSISTANT_MODEL')]
        private string $model,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '' && $this->model !== '';
    }

    /**
     * One turn of the model: the message it answers with, a reply or the
     * tools it wants run.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     *
     * @throws AssistantUnavailable when the service cannot be reached or answers with an error
     */
    public function complete(array $messages, array $tools): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
                'auth_bearer' => $this->apiKey,
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                ],
                'timeout' => 60,
            ]);

            $data = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new AssistantUnavailable('The assistant service could not be reached: ' . $e->getMessage(), 0, $e);
        }

        $message = $data['choices'][0]['message'] ?? null;

        if ($status >= 300 || ! is_array($message)) {
            throw new AssistantUnavailable('The assistant service answered with HTTP ' . $status . '.');
        }

        return $message;
    }
}
