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

namespace Augias\CoreBundle\Repository;

use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Collator;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Locale;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use function sprintf;
use function str_contains;
use function usort;

/**
 * @extends EntityRepository<Category>
 */
class CategoryRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * The categories offered in a given place. This is what keeps one shared
     * list from putting "Bank charges" in the catalogue's dropdown.
     *
     * Returns a builder rather than results because every caller is an
     * `EntityType` form field, which wants a `query_builder`.
     */
    public function forUsage(CategoryUsage $usage): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->andWhere(sprintf('c.%s = true', $usage->propertyName()))
            ->orderBy('c.name', 'ASC');
    }

    /**
     * The same list in the order a reader expects. The database sorts by byte,
     * which puts "Câblage" after "Conseil", and lowercase and accented names
     * after "Z"; a collator sorts them as a dictionary would.
     *
     * @return list<Category>
     */
    public function sortedForUsage(CategoryUsage $usage): array
    {
        /** @var list<Category> $categories */
        $categories = $this->forUsage($usage)->getQuery()->getResult();
        // POSIX is the C locale - byte order again - which is what the
        // command line runs under; the root collation sorts as a dictionary.
        $locale = Locale::getDefault();
        $collator = new Collator(str_contains($locale, 'POSIX') ? 'root' : $locale);

        usort(
            $categories,
            static fn (Category $a, Category $b): int => (int) $collator->compare((string) $a->getName(), (string) $b->getName()),
        );

        return $categories;
    }

    public function delete(Category $category): void
    {
        $this->getEntityManager()->remove($category);
        $this->getEntityManager()->flush();
    }
}
