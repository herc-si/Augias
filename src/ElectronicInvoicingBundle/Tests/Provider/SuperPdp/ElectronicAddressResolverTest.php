<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\ElectronicAddressResolver;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function array_map;
use function json_encode;
use function str_contains;

#[CoversClass(ElectronicAddressResolver::class)]
final class ElectronicAddressResolverTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    /**
     * What the sandbox registers for Burger Queen: a root address and
     * variants of it. The root is where invoices go.
     */
    public function testTheCompanysOwnAddressIsTheRootTheOthersExtend(): void
    {
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'SIRET', 'value' => '000000002']);

        $this->resolver(own: ['0225:315143296_92569', '0225:315143296_92569_replyto', '0225:315143296_92569_demo1'])
            ->resolve('a-token', $this->company, null);

        self::assertSame('315143296_92569', $this->companyAddress());
    }

    /**
     * An establishment's own address beats the company's.
     */
    public function testABuyersEstablishmentAddressIsPreferred(): void
    {
        $buyer = $this->buyer('12345678900012');

        $this->resolver(directory: ['0225:123456789', '0225:123456789_12345678900012'])
            ->resolve('a-token', $this->company, $buyer);

        self::assertSame('123456789_12345678900012', $this->address($buyer));
    }

    public function testAnAddressAlreadyGivenIsNeverReplaced(): void
    {
        $buyer = $this->buyer('12345678900012');
        $buyer->addTaxIdentifier(new TaxIdentifier()->setLabel('Adresse électronique')->setValue('123456789_COMPTA'));

        $this->resolver(directory: ['0225:123456789'])->resolve('a-token', $this->company, $buyer);

        self::assertSame('123456789_COMPTA', $this->address($buyer));
    }

    /**
     * Nothing in the directory — as for every sandbox company — leaves the
     * e-invoice on the SIREN, and nothing is invented.
     */
    public function testNothingFoundRecordsNothing(): void
    {
        $buyer = $this->buyer('12345678900012');

        self::assertFalse($this->resolver()->resolve('a-token', $this->company, $buyer), 'Nobody to deliver a status to.');

        self::assertNull($this->address($buyer));
        self::assertNull($this->companyAddress());
    }

    public function testABuyerWithATypedAddressIsReachable(): void
    {
        $buyer = $this->buyer('12345678900012');
        $buyer->addTaxIdentifier(new TaxIdentifier()->setLabel('Adresse électronique')->setValue('123456789_COMPTA'));

        self::assertTrue($this->resolver()->buyerReachable('a-token', $buyer));
    }

    public function testABuyerFoundInTheDirectoryIsReachable(): void
    {
        $buyer = $this->buyer('98765432100015');

        self::assertTrue($this->resolver(directory: ['0225:987654321'])->buyerReachable('a-token', $buyer));
        self::assertSame('987654321', $this->address($buyer));
    }

    /**
     * Without a SIREN, or with a directory that does not answer, nobody
     * knows: the user is not told something that may be wrong.
     */
    public function testWithoutASirenNobodyKnows(): void
    {
        self::assertNull($this->resolver()->buyerReachable('a-token', ClientFactory::createOne(['company' => $this->company])));
    }

    public function testADirectoryThatDoesNotAnswerSaysNothing(): void
    {
        self::assertNull($this->resolver(down: true)->buyerReachable('a-token', $this->buyer('12345678900012')));
    }

    /**
     * @param list<string> $own
     * @param list<string> $directory
     */
    private function resolver(array $own = [], array $directory = [], bool $down = false): ElectronicAddressResolver
    {
        $client = new SuperPdpClient(new MockHttpClient(static function (string $method, string $url) use ($own, $directory, $down): MockResponse {
            if ($down) {
                return new MockResponse('', ['http_code' => 503]);
            }

            $identifiers = str_contains($url, 'french_directory') ? $directory : $own;

            return new MockResponse((string) json_encode(['data' => array_map(static fn (string $id): array => ['identifier' => $id], $identifiers)]));
        }));

        self::getContainer()->set(SuperPdpClient::class, $client);

        return self::getContainer()->get(ElectronicAddressResolver::class);
    }

    private function buyer(string $siret): Client
    {
        $buyer = ClientFactory::createOne(['company' => $this->company]);
        $buyer->setSiret($siret);

        return $buyer;
    }

    private function address(Client $client): ?string
    {
        foreach ($client->getTaxIdentifiers() as $identifier) {
            if ($identifier->getLabel() === 'Adresse électronique') {
                return $identifier->getValue();
            }
        }

        return null;
    }

    private function companyAddress(): ?string
    {
        foreach (self::getContainer()->get(TaxIdentifierRepository::class)->findCompanyIdentifiers($this->company->getId()) as $identifier) {
            if ($identifier->getLabel() === 'Adresse électronique') {
                return $identifier->getValue();
            }
        }

        return null;
    }
}
