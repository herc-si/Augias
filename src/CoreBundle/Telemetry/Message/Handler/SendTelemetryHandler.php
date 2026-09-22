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

namespace Augias\CoreBundle\Telemetry\Message\Handler;

use Augias\CoreBundle\Telemetry\Message\SendTelemetryMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use function rtrim;
use function Sentry\captureException;

/**
 * @see \Augias\CoreBundle\Tests\Telemetry\Message\Handler\SendTelemetryHandlerTest
 */
#[AsMessageHandler]
final readonly class SendTelemetryHandler
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'AUGIAS_TELEMETRY_URL')]
        private string $telemetryUrl,
    ) {
    }

    public function __invoke(SendTelemetryMessage $message): void
    {
        // No collector, nothing sent. Telemetry::isEnabled() already refuses to
        // queue a message when none is configured, but this is the backstop for
        // what goes round it: an event dispatched with $force — the installer
        // reports itself that way, having just written the setting to disk —
        // and any message still in the queue from a deployment that had a
        // collector configured.
        if ($this->telemetryUrl === '') {
            return;
        }

        $endpoint = rtrim($this->telemetryUrl, '/') . '/v1/' . $message->type;

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $message->payload,
                'timeout' => 4,
                'max_duration' => 5,
            ]);

            // Reading the status code forces the request to complete. Any transport
            // error surfaces here and is caught below. A non-2xx status is treated
            // as a (silent) failure — telemetry is best-effort with no retries.
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->debug('Telemetry request returned a non-success status', [
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                ]);
            }
        } catch (Throwable $e) {
            // Swallow ALL failures and return normally so the message is acked and
            // never retried or moved to the failed queue. A slow or unreachable
            // collector must never degrade the app.
            $this->logger->debug('Telemetry request failed', [
                'endpoint' => $endpoint,
                'exception' => $e,
            ]);

            // Report to Sentry so we still learn about telemetry failures. This is a
            // no-op when Sentry is not enabled.
            captureException($e);
        }
    }
}
