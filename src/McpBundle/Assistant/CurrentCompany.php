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

namespace Augias\McpBundle\Assistant;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * The company the assistant answers about: the one open in the session.
 */
final readonly class CurrentCompany
{
    public function __construct(
        private CompanySelector $companySelector,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(): ?Company
    {
        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->entityManager->find(Company::class, $companyId) : null;

        return $company instanceof Company ? $company : null;
    }
}
