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

namespace Augias\SaasBundle\Payment\Stripe;

use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function http_build_query;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Stripe's REST API, called directly: the stripe/stripe-php installed is the
 * 7.x that payum/stripe pins, far behind the API.
 *
 * Stripe takes form-encoded parameters, nested with brackets
 * (`line_items[0][price]`), and answers JSON.
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\StripeIntegrationTest
 */
final readonly class StripeApi
{
    public const string API_VERSION = '2024-06-20';

    public function __construct(
        #[Autowire(service: 'stripe')]
        private HttpClientInterface $client,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $parameters = []): array
    {
        return $this->request('POST', $path, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query($parameters),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        try {
            $response = $this->client->request($method, $path, $options);
            $data = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new PaymentIntegrationException(sprintf('Stripe %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if ($status >= 300) {
            $message = is_array($data['error'] ?? null) && is_string($data['error']['message'] ?? null) ? $data['error']['message'] : 'no message';

            throw new PaymentIntegrationException(sprintf('Stripe %s %s failed (HTTP %d): %s', $method, $path, $status, $message));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
