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

namespace Augias\CoreBundle\Tests\Company;

use Augias\CoreBundle\Company\AllUserCompanies;
use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(AllUserCompanies::class)]
final class AllUserCompaniesTest extends TestCase
{
    public function testReturnsAllCompaniesAsList(): void
    {
        $companyA = new Company();
        $companyB = new Company();

        $user = new User();
        $user->addCompany($companyA);
        $user->addCompany($companyB);

        $companies = new AllUserCompanies()->getFor($user);

        self::assertSame([$companyA, $companyB], $companies);
        self::assertSame([0, 1], array_keys($companies));
    }

    public function testReturnsEmptyListWhenUserHasNoCompanies(): void
    {
        $user = new User();

        self::assertSame([], new AllUserCompanies()->getFor($user));
    }

    public function testReturnsListWhenBackingCollectionHasNonZeroIndexedKeys(): void
    {
        // The backing Collection is keyed by association ordinal, which can be
        // non-sequential after removals — make sure we always return list<Company>.
        $companyA = new Company();
        $companyB = new Company();

        $user = new User();
        // Simulate a sparse collection by reflection — same shape as a removeCompany()
        // followed by an addCompany().
        $reflection = new ReflectionProperty(User::class, 'memberships');
        $reflection->setValue($user, new ArrayCollection([
            5 => new Membership($user, $companyA, CompanyRole::Admin),
            9 => new Membership($user, $companyB, CompanyRole::Admin),
        ]));

        $companies = new AllUserCompanies()->getFor($user);

        self::assertSame([$companyA, $companyB], $companies);
        self::assertSame([0, 1], array_keys($companies));
    }
}
