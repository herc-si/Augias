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

namespace Augias\ClientBundle\Tests\Functional\Api;

use Augias\ApiBundle\Test\ApiTestCase;
use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use function array_map;

#[Group('functional')]
final class ClientTest extends ApiTestCase
{
    protected function getResourceClass(): string
    {
        return Client::class;
    }

    public function testCreate(): void
    {
        $data = [
            'name' => 'Dummy User',
            'contacts' => [],
            'credit' => '125.50',
        ];

        $result = $this->requestPost('/api/clients', $data);

        self::assertArrayHasKey('id', $result);
        self::assertTrue(Ulid::isValid($result['id'], Ulid::FORMAT_BASE_32));
        unset($result['id'], $result['@id']);

        self::assertEqualsCanonicalizing([
            '@context' => $this->getContextForResource(Client::class),
            '@type' => 'https://schema.org/Corporation',
            'name' => 'Dummy User',
            'website' => null,
            'status' => 'active',
            'currencyCode' => null,
            'taxIdentifiers' => [],
            'contacts' => [],
            'quotes' => [],
            'invoices' => [],
            'recurringInvoices' => [],
            'payments' => [],
            'addresses' => [],
            'credit' => 125.5,
            'customFields' => [],
        ], $result);
    }

    public function testGetCollection(): void
    {
        ClientFactory::createMany(3);

        $data = $this->requestGetCollection('/api/clients');

        self::assertArraySubset([
            '@context' => $this->getContextForResource(Client::class),
            '@id' => '/api/clients',
            '@type' => 'Collection',
        ], $data);
    }

    public function testCannotAccessClientFromDifferentCompany(): void
    {
        $otherCompany = CompanyFactory::new()->create();
        self::getContainer()->get(CompanySelector::class)->switchCompany($otherCompany->getId());
        $foreignClient = ClientFactory::createOne(['company' => $otherCompany]);
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        $response = self::$client->request('GET', $this->getIriFromResource($foreignClient), [
            'headers' => ['accept' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testDelete(): void
    {
        $client = ClientFactory::createOne();

        $this->requestDelete($this->getIriFromResource($client));
    }

    /**
     * @throws TransportExceptionInterface
     * @throws JsonException
     */
    public function testGet(): void
    {
        $client = ClientFactory::createOne([
            'addresses' => [
                $address = new Address(),
            ],
        ]);

        $contacts = ContactFactory::new([
            'client' => $client,
        ])->many(1, 5)
            ->create();

        $data = $this->requestGet($this->getIriFromResource($client));

        self::assertEqualsCanonicalizing([
            '@context' => $this->getContextForResource($client),
            '@id' => $this->getIriFromResource($client),
            '@type' => 'https://schema.org/Corporation',
            'id' => $client->getId()
                ->toString(),
            'name' => $client->getName(),
            'website' => $client->getWebsite(),
            'status' => $client->getStatus()?->value,
            'currencyCode' => $client->getCurrencyCode(),
            'taxIdentifiers' => [],
            'contacts' => array_map($this->getIriFromResource(...), array_map(static fn (Contact $proxy) => $proxy, $contacts)),
            'quotes' => [],
            'invoices' => [],
            'recurringInvoices' => [],
            'payments' => [],
            'addresses' => [
                $this->getIriFromResource($address),
            ],
            'credit' => 0,
            'customFields' => [],
        ], $data);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testEdit(): void
    {
        $client = ClientFactory::createOne();
        self::assertInstanceOf(Client::class, $client);

        $contacts = ContactFactory::new([
            'client' => $client,
        ])->many(4, 15)
            ->create();

        $contactInfo = array_map($this->getIriFromResource(...), $contacts);

        $data = $this->requestPatch(
            $this->getIriFromResource($client),
            [
                'name' => 'New Test',
                'contacts' => $contactInfo,
            ]
        );

        self::assertEqualsCanonicalizing([
            '@context' => $this->getContextForResource($client),
            '@id' => $this->getIriFromResource($client),
            '@type' => 'https://schema.org/Corporation',
            'id' => $client->getId()
                ->toString(),
            'name' => 'New Test',
            'website' => $client->getWebsite(),
            'status' => $client->getStatus()?->value,
            'currencyCode' => $client->getCurrencyCode(),
            'taxIdentifiers' => [],
            'contacts' => $contactInfo,
            'quotes' => [],
            'invoices' => [],
            'recurringInvoices' => [],
            'payments' => [],
            'addresses' => [],
            'credit' => 0,
            'customFields' => [],
        ], $data);
    }

    /**
     * The form never offered an unsupported currency, so nothing stopped one
     * arriving here — and a stored code the money layer cannot format turns
     * every page that shows an amount for this client into a 500.
     */
    public function testAClientCannotBeCreatedWithACurrencyTheBooksCannotFormat(): void
    {
        self::$client->request(
            'POST',
            '/api/clients',
            [
                'json' => [
                    'name' => 'Havana Trading',
                    'contacts' => [],
                    'currencyCode' => 'CUC',
                ],
                'headers' => [
                    'content-type' => 'application/ld+json',
                    'accept' => 'application/ld+json',
                ],
            ]
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
