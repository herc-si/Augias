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

namespace Augias\ClientBundle\Tests\Twig\Components;

use Augias\ClientBundle\Registry\CompanyRegistry;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Tests\Registry\CompanyRegistryTest;
use Augias\ClientBundle\Twig\Components\ClientForm;
use Augias\CoreBundle\Test\LiveComponentTest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Uid\Ulid;

#[CoversClass(ClientForm::class)]
final class ClientFormTest extends LiveComponentTest
{
    public function testRender(): void
    {
        $component = $this
            ->createLiveComponent(name: ClientForm::class, client: $this->client)
            ->actingAs($this->getUser());

        $this->assertMatchesHtmlSnapshot($this->replaceChecksum($component->render()->toString()));
    }

    /**
     * Picked in the register, a company fills in the name, the identifiers,
     * the VAT number and the head office address.
     */
    public function testAPickInTheRegisterFillsTheClientIn(): void
    {
        self::getContainer()->set(CompanyRegistry::class, new CompanyRegistry(
            new MockHttpClient(static fn (): JsonMockResponse => new JsonMockResponse(CompanyRegistryTest::DECATHLON)),
            new ArrayAdapter(),
            new NullLogger(),
        ));

        $component = $this
            ->createLiveComponent(name: ClientForm::class, client: $this->client)
            ->actingAs($this->getUser());

        $component->set('registryQuery', 'decathlon');
        self::assertStringContainsString('SIREN 306138900', $component->render()->toString());

        $component->call('fillFromRegistry', ['siren' => '306138900']);

        $values = $component->component()->formValues;
        self::assertSame('DECATHLON', $values['name']);
        self::assertSame('306138900', $values['siren']);
        self::assertSame('30613890001294', $values['siret']);
        self::assertSame('FR51306138900', $values['vatNumber']);
        $address = reset($values['addresses']);
        self::assertSame('4 BOULEVARD DE MONS', $address['street1']);
        self::assertSame('59650', $address['zip']);
        self::assertSame('FR', $address['country']);
    }

    public function testRenderWithExistingData(): void
    {
        $user = $this->getUser();

        $client = ClientFactory::createOne([
            'name' => 'Foo Bar',
            'website' => 'https://example.com',
            'currencyCode' => 'SBD',
            'company' => $this->company
        ]);

        $client->setId(Ulid::fromString('0f9e91e6-06ba-11ef-a331-5a2cf21a5680'));

        $component = $this
            ->createLiveComponent(ClientForm::class, ['client' => $client])
            ->actingAs($user);

        $this->assertMatchesHtmlSnapshot(
            $this->replaceUuid(
                $this->replaceChecksum($component->render()->toString())
            )
        );
    }
}
