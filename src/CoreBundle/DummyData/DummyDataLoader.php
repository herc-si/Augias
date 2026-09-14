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

namespace Augias\CoreBundle\DummyData;

use Augias\CoreBundle\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DummyDataLoader
{
    /**
     * @param iterable<DummyDataLoaderInterface> $loaders
     */
    public function __construct(
        private iterable $loaders,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * All of it, or none of it.
     *
     * None of the loaders is idempotent: they insert rows whose columns are
     * unique per company — a catalog category's name, a tax rate — so running
     * one twice for the same company fails on the second pass. That is fine as
     * long as a failed run leaves nothing behind. Without a transaction it does
     * not: the loaders that already ran keep their rows, and the next attempt
     * dies on "UNIQUE constraint failed: categories.name, categories.company_id"
     * rather than on whatever actually went wrong, leaving the company in a
     * half-loaded state that has to be cleaned out by hand.
     *
     * Wrapping the whole set means a failure is reported as itself, and the
     * company is exactly as it was before.
     */
    public function load(Company $company): void
    {
        $this->entityManager->wrapInTransaction(function () use ($company): void {
            foreach ($this->loaders as $loader) {
                $loader->load($company);
            }
        });
    }
}
