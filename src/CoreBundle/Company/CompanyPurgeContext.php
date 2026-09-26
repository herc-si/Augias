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

namespace Augias\CoreBundle\Company;

use Augias\CoreBundle\Entity\Company;
use Symfony\Component\Uid\Ulid;
use function array_diff;
use function array_values;
use function in_array;

/**
 * Which companies are being deleted at the end of their closure, right now.
 *
 * Shared by CompanyClosure, which says so, and the retention guard, which
 * lets their issued documents go — and nothing else's.
 */
final class CompanyPurgeContext
{
    /** @var list<string> */
    private array $purging = [];

    /**
     * @template T
     *
     * @param callable(): T $purge
     *
     * @return T
     */
    public function during(Company $company, callable $purge): mixed
    {
        $id = $company->getId()->toBase32();
        $this->purging[] = $id;

        try {
            return $purge();
        } finally {
            $this->purging = array_values(array_diff($this->purging, [$id]));
        }
    }

    public function isPurging(Company | Ulid | null $company): bool
    {
        if (null === $company) {
            return false;
        }

        return in_array(($company instanceof Company ? $company->getId() : $company)->toBase32(), $this->purging, true);
    }
}
