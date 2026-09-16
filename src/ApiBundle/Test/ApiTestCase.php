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

namespace Augias\ApiBundle\Test;

use ApiPlatform\JsonLd\ContextBuilderInterface;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase as ApiPlatformTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Augias\ApiBundle\ApiTokenManager;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function is_object;

/**
 * @codeCoverageIgnore
 */
abstract class ApiTestCase extends ApiPlatformTestCase
{
    use EnsureApplicationInstalled;

    protected static Client $client;

    protected Generator $faker;

    protected User $user;

    protected static ?bool $alwaysBootKernel = false;

    /**
     * @return class-string
     */
    abstract protected function getResourceClass(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faker = Factory::create();

        $this->user = UserFactory::createOne(['companies' => [$this->company]]);

        $tokenManager = self::getContainer()->get(ApiTokenManager::class);
        $generated = $tokenManager->getOrCreate($this->user, 'Functional Test');

        self::$client = static::createClient(defaultOptions: ['headers' => ['X-API-TOKEN' => $generated->plaintext]]);

        // We need to switch the company again,
        // because the ::createClient call resets the container
        // so we lose the state on the CompanySelector service
        static::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function requestPost(string $uri, array $data, array $headers = []): array
    {
        $headers = [
            'content-type' => 'application/ld+json',
            'accept' => 'application/ld+json',
            ...$headers
        ];

        $response = $this->requestExpectingSuccess(
            method: Request::METHOD_POST,
            url: $uri,
            options: [
                'json' => $data,
                'headers' => $headers,
            ]
        );

        static::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        static::assertResponseFormatSame('jsonld');
        static::assertMatchesResourceItemJsonSchema($this->getResourceClass());

        return $response->toArray(false);
    }

    /**
     * PATCH makes incremental updates to the resource
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function requestPatch(string $uri, array $data, array $headers = []): array
    {
        $headers = [
            'content-type' => 'application/merge-patch+json',
            'accept' => 'application/ld+json',
            ...$headers
        ];

        $response = $this->requestExpectingSuccess(
            method: Request::METHOD_PATCH,
            url: $uri,
            options: [
                'json' => $data,
                'headers' => $headers,
            ]
        );

        static::assertResponseStatusCodeSame(Response::HTTP_OK);
        static::assertResponseFormatSame('jsonld');
        static::assertMatchesResourceItemJsonSchema($this->getResourceClass());

        return $response->toArray(false);
    }

    /**
     * PUT replaces the resource entirely
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function requestPut(string $uri, array $data, array $headers = []): array
    {
        $headers = [
            'content-type' => 'application/ld+json',
            'accept' => 'application/ld+json',
            ...$headers
        ];

        $response = $this->requestExpectingSuccess(
            method: Request::METHOD_PUT,
            url: $uri,
            options: [
                'json' => $data,
                'headers' => $headers,
            ]
        );

        static::assertResponseStatusCodeSame(Response::HTTP_OK);
        static::assertResponseFormatSame('jsonld');
        static::assertMatchesResourceItemJsonSchema($this->getResourceClass());

        return $response->toArray(false);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function requestGet(string $uri, array $headers = []): array
    {
        $headers = [
            'content-type' => 'application/ld+json',
            'accept' => 'application/ld+json',
            ...$headers
        ];

        $response = $this->requestExpectingSuccess(
            method: Request::METHOD_GET,
            url: $uri,
            options: [
                'headers' => $headers,
            ]
        );

        static::assertResponseStatusCodeSame(Response::HTTP_OK);
        static::assertResponseFormatSame('jsonld');
        static::assertMatchesResourceItemJsonSchema($this->getResourceClass());

        return $response->toArray(false);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function requestGetCollection(string $uri, array $headers = []): array
    {
        $headers = [
            'content-type' => 'application/ld+json',
            'accept' => 'application/ld+json',
            ...$headers
        ];

        $response = $this->requestExpectingSuccess(
            method: Request::METHOD_GET,
            url: $uri,
            options: [
                'headers' => $headers,
            ]
        );

        static::assertResponseStatusCodeSame(Response::HTTP_OK);
        static::assertResponseFormatSame('jsonld');
        static::assertMatchesResourceCollectionJsonSchema($this->getResourceClass());

        return $response->toArray(false);
    }

    /**
     * @param array<string, mixed> $data
     * @param class-string $outputResourceClass
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function requestPostExpecting(string $uri, array $data, string $outputResourceClass, array $headers = []): array
    {
        $headers = [
            'content-type' => 'application/ld+json',
            'accept' => 'application/ld+json',
            ...$headers
        ];

        $response = $this->requestExpectingSuccess(
            method: Request::METHOD_POST,
            url: $uri,
            options: [
                'json' => $data,
                'headers' => $headers,
            ]
        );

        static::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        static::assertResponseFormatSame('jsonld');
        static::assertMatchesResourceItemJsonSchema($outputResourceClass);

        return $response->toArray(false);
    }

    /**
     * @param array<string,string> $headers
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    protected function requestDelete(string $uri, array $headers = []): void
    {
        $headers = [
            'content-type' => 'application/ld+json',
            'accept' => 'application/ld+json',
            ...$headers
        ];

        $response = $this->requestExpectingSuccess(
            method: Request::METHOD_DELETE,
            url: $uri,
            options: [
                'headers' => $headers,
            ]
        );

        static::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmpty($response->getContent(false));
    }

    protected function getContextForResource(object | string $resource): string
    {
        /** @var ContextBuilderInterface $contextBuilder */
        $contextBuilder = static::getContainer()->get('api_platform.jsonld.context_builder');

        return $contextBuilder->getResourceContextUri(is_object($resource) ? $resource::class : $resource);
    }

    /**
     * Issues a request for the helpers above, with the kernel rethrowing instead
     * of rendering an error page.
     *
     * Every one of them asserts a 2xx, so a 500 is never their expected outcome —
     * it is a failure whose cause the assertion cannot show. "Expected 201, got
     * 500" is all the evidence a flake has ever left here, which is why
     * QuoteTransitionTest::testSendQuote went undiagnosed on MariaDB 11.0. With
     * the exception rethrown, the next occurrence carries its stack trace into the
     * test output.
     *
     * Deliberately not set once in setUp(): tests that assert a 4xx call the
     * client directly, and for them an error page is the right shape. Hence the
     * restore in the finally — the toggle lives on the browser and outlives the
     * call.
     *
     * @param array<string, mixed> $options
     */
    private function requestExpectingSuccess(string $method, string $url, array $options): ResponseInterface
    {
        $browser = self::$client->getKernelBrowser();
        $browser->catchExceptions(false);

        try {
            return self::$client->request(method: $method, url: $url, options: $options);
        } finally {
            $browser->catchExceptions(true);
        }
    }
}
