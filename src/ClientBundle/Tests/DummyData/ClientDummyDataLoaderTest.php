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

namespace Augias\ClientBundle\Tests\DummyData;

use Augias\ClientBundle\DummyData\ClientDummyDataLoader;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_map;
use function array_unique;
use function array_values;

#[CoversClass(ClientDummyDataLoader::class)]
final class ClientDummyDataLoaderTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    /**
     * A demo client billed in a currency its company does not use demonstrates
     * nothing — and the currency is the client's, so nothing downstream can
     * correct it afterwards.
     */
    public function testClientsAreBilledInTheCurrencyOfTheirCompany(): void
    {
        $container = self::getContainer();

        $config = $container->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);

        // Deliberately neither the fallback nor a plausible default, so that a
        // currency that merely happens to match cannot pass this.
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'CHF');

        $loader = $container->get(ClientDummyDataLoader::class);
        self::assertInstanceOf(ClientDummyDataLoader::class, $loader);

        $loader->load($this->company);

        $entityManager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $repository = $entityManager->getRepository(Client::class);
        self::assertInstanceOf(ClientRepository::class, $repository);

        $clients = $repository->findAll();
        self::assertNotEmpty($clients);

        $currencies = array_values(array_unique(array_map(static fn (Client $client): ?string => $client->getCurrencyCode(), $clients)));

        self::assertSame(['CHF'], $currencies);
    }
}
