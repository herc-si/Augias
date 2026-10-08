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

namespace Augias\CoreBundle\Tests\Repository;

use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_map;

/**
 * Purchases and the catalogue share one category list, which only works because
 * each dropdown asks for its own side. If this filtering ever breaks, the
 * symptom is subtle and easy to live with for a while — a slightly-too-long
 * dropdown — so it is pinned down here rather than left to be noticed.
 */
#[CoversClass(CategoryRepository::class)]
final class CategoryRepositoryTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testForUsageReturnsOnlyTheCategoriesOfThatSide(): void
    {
        $this->persist('Loyer', purchases: true, catalog: false);
        $this->persist('Formation', purchases: false, catalog: true);
        $this->persist('Matériel', purchases: true, catalog: true);

        self::assertSame(['Loyer', 'Matériel'], $this->namesFor(CategoryUsage::Purchase));
        self::assertSame(['Formation', 'Matériel'], $this->namesFor(CategoryUsage::Catalog));
    }

    /**
     * Alphabetical, because these land in a dropdown the user has to scan.
     */
    public function testForUsageOrdersByName(): void
    {
        foreach (['Zèbre', 'Abeille', 'Marmotte'] as $name) {
            $this->persist($name, purchases: true, catalog: false);
        }

        self::assertSame(['Abeille', 'Marmotte', 'Zèbre'], $this->namesFor(CategoryUsage::Purchase));
    }

    /**
     * What the dropdowns use: the database's byte order put "Câblage" after
     * "Conseil" and lowercase or accented names after "Z".
     */
    public function testSortedForUsageOrdersAsADictionaryWould(): void
    {
        foreach (['Zinguerie', 'Électricité', 'Conseil', 'audit', 'Câblage'] as $name) {
            $this->persist($name, purchases: false, catalog: true);
        }

        $repository = self::getContainer()->get(CategoryRepository::class);

        self::assertSame(
            ['audit', 'Câblage', 'Conseil', 'Électricité', 'Zinguerie'],
            array_map(static fn (Category $category): string => (string) $category->getName(), $repository->sortedForUsage(CategoryUsage::Catalog)),
        );
    }

    public function testACategoryUsedNowhereIsOfferedNowhere(): void
    {
        $this->persist('Orpheline', purchases: false, catalog: false);

        self::assertSame([], $this->namesFor(CategoryUsage::Purchase));
        self::assertSame([], $this->namesFor(CategoryUsage::Catalog));
    }

    private function persist(string $name, bool $purchases, bool $catalog): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $category = new Category()
            ->setName($name)
            ->setUsedForPurchases($purchases)
            ->setUsedForCatalog($catalog);
        $category->setCompany($this->company);

        $entityManager->persist($category);
        $entityManager->flush();
    }

    /**
     * @return list<string>
     */
    private function namesFor(CategoryUsage $usage): array
    {
        $repository = self::getContainer()->get(CategoryRepository::class);

        return array_map(
            static fn (Category $category): string => (string) $category->getName(),
            $repository->forUsage($usage)->getQuery()->getResult(),
        );
    }
}
