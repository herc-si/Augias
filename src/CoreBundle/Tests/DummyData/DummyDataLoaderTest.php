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

namespace Augias\CoreBundle\Tests\DummyData;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\DummyData\DummyDataLoader;
use Augias\CoreBundle\DummyData\DummyDataLoaderInterface;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DummyDataLoader::class)]
final class DummyDataLoaderTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testLoadsEveryLoaderInTurn(): void
    {
        $loader = new DummyDataLoader(
            [$this->clientLoader('Acme'), $this->clientLoader('Globex')],
            $this->entityManager,
        );

        $loader->load($this->companyReference());

        self::assertSame(2, $this->clientCount());
    }

    /**
     * None of the loaders is idempotent — they insert rows that are unique per
     * company — so a half-finished run is not merely untidy: the next attempt
     * dies on that leftover uniqueness rather than on whatever actually went
     * wrong, and the company has to be cleaned out by hand.
     *
     * The failure must therefore take everything with it.
     */
    public function testAFailingLoaderLeavesNothingBehind(): void
    {
        $loader = new DummyDataLoader(
            [$this->clientLoader('Acme'), $this->failingLoader()],
            $this->entityManager,
        );

        try {
            $loader->load($this->companyReference());
            self::fail('the failing loader should have thrown');
        } catch (RuntimeException $e) {
            self::assertSame('this loader blew up', $e->getMessage());
        }

        self::assertSame(0, $this->clientCount(), 'the client written before the failure must be rolled back');
    }

    private function clientLoader(string $name): DummyDataLoaderInterface
    {
        return new class($this->entityManager, $name) implements DummyDataLoaderInterface {
            public function __construct(
                private readonly EntityManagerInterface $entityManager,
                private readonly string $name,
            ) {
            }

            public function load(Company $company): void
            {
                $client = new Client();
                $client->setName($this->name);
                $client->setCompany($company);

                $this->entityManager->persist($client);
                $this->entityManager->flush();
            }

            public static function getPriority(): int
            {
                return 0;
            }
        };
    }

    private function failingLoader(): DummyDataLoaderInterface
    {
        return new class() implements DummyDataLoaderInterface {
            public function load(Company $company): void
            {
                throw new RuntimeException('this loader blew up');
            }

            public static function getPriority(): int
            {
                return 0;
            }
        };
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function clientCount(): int
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Client::class)
            ->count([]);
    }
}
