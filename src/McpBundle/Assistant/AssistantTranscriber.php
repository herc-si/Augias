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

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function is_array;
use function is_string;
use function json_decode;
use function preg_replace;
use function trim;

/**
 * What the person said into the assistant's microphone, as text, through an
 * OpenAI-compatible `audio/transcriptions` endpoint: Whisper at Scaleway
 * by choice (Rémi, 26/11/2026), so the voice goes where the questions go,
 * in Paris.
 *
 * AUGIAS_ASSISTANT_TRANSCRIPTION_URL is the endpoint itself, e.g.
 * `https://api.scaleway.ai/v1/audio/transcriptions`;
 * AUGIAS_ASSISTANT_TRANSCRIPTION_MODEL the model (`whisper-large-v3` there). The key
 * is the assistant's. Without them, there is no microphone.
 *
 * Scaleway answers with the text at once (`text`), taken as it is.
 * Infomaniak, used before, answers with a batch to fetch (`batch_id`, then
 * `…/results/<batch_id>` until it is no longer pending): still followed.
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantTranscriberTest
 */
final readonly class AssistantTranscriber
{
    /** How long a transcription is waited for. */
    public const int WAIT_SECONDS = 30;

    private const int POLL_MICROSECONDS = 500_000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        #[Autowire(env: 'AUGIAS_ASSISTANT_TRANSCRIPTION_URL')]
        private string $url,
        #[Autowire(env: 'AUGIAS_ASSISTANT_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'AUGIAS_ASSISTANT_TRANSCRIPTION_MODEL')]
        private string $model,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->apiKey !== '' && $this->model !== '';
    }

    /**
     * @throws AssistantUnavailable when the service cannot be reached, fails, or takes too long
     */
    public function transcribe(string $path, string $filename, string $contentType, string $language): string
    {
        $form = new FormDataPart([
            'model' => $this->model,
            'language' => $language,
            'response_format' => 'json',
            'file' => DataPart::fromPath($path, $filename, $contentType),
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'auth_bearer' => $this->apiKey,
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => self::WAIT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new AssistantUnavailable('The transcription service could not be reached: ' . $e->getMessage(), 0, $e);
        }

        if ($status >= 300) {
            throw new AssistantUnavailable('The transcription service answered with HTTP ' . $status . '.');
        }

        if (is_string($data['text'] ?? null)) {
            return trim($data['text']);
        }

        if (! is_string($data['batch_id'] ?? null)) {
            throw new AssistantUnavailable('The transcription service gave neither a text nor a batch.');
        }

        return $this->fetch($data['batch_id']);
    }

    /**
     * @throws AssistantUnavailable
     */
    private function fetch(string $batchId): string
    {
        $resultsUrl = (string) preg_replace('#/openai/audio/transcriptions/?$#', '/results/' . $batchId, $this->url);
        $until = $this->clock->now()->getTimestamp() + self::WAIT_SECONDS;

        do {
            $this->clock->sleep(self::POLL_MICROSECONDS / 1_000_000);

            try {
                $result = $this->httpClient->request('GET', $resultsUrl, ['auth_bearer' => $this->apiKey])->toArray(false);
            } catch (ExceptionInterface $e) {
                throw new AssistantUnavailable('The transcription could not be fetched: ' . $e->getMessage(), 0, $e);
            }

            $status = $result['status'] ?? null;

            if ($status === 'success') {
                return $this->text($result['data'] ?? null);
            }

            if ($status !== 'pending' && $status !== 'processing') {
                throw new AssistantUnavailable('The transcription failed (' . (is_string($status) ? $status : 'no status') . ').');
            }
        } while ($this->clock->now()->getTimestamp() < $until);

        throw new AssistantUnavailable('The transcription took too long.');
    }

    private function text(mixed $data): string
    {
        // The text itself with response_format=text, an object with `text` with json.
        if (is_string($data)) {
            $decoded = json_decode($data, true);

            return trim(is_array($decoded) && is_string($decoded['text'] ?? null) ? $decoded['text'] : $data);
        }

        return is_array($data) && is_string($data['text'] ?? null) ? trim($data['text']) : '';
    }
}
