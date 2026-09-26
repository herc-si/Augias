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

namespace Augias\UserBundle\Tests\Enum;

use Augias\UserBundle\Enum\CompanyPermission as P;
use Augias\UserBundle\Enum\CompanyRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The grid of rights agreed on 26/09/2026, read back from the roles.
 */
#[CoversClass(CompanyRole::class)]
final class CompanyRoleTest extends TestCase
{
    /**
     * @return iterable<string, array{CompanyRole, P, bool}>
     */
    public static function grid(): iterable
    {
        $expected = [
            'owner' => [P::BillingRead, P::BillingWrite, P::AccountingWrite, P::Export, P::Settings, P::ManageMembers, P::CloseCompany],
            'admin' => [P::BillingRead, P::BillingWrite, P::AccountingWrite, P::Export, P::Settings, P::ManageMembers],
            'billing' => [P::BillingRead, P::BillingWrite],
            'accountant' => [P::BillingRead, P::AccountingWrite, P::Export],
        ];

        foreach (CompanyRole::cases() as $role) {
            foreach (P::cases() as $permission) {
                yield $role->value . ' ' . $permission->value => [$role, $permission, in_array($permission, $expected[$role->value], true)];
            }
        }
    }

    #[DataProvider('grid')]
    public function testTheGrid(CompanyRole $role, P $permission, bool $allowed): void
    {
        self::assertSame($allowed, $role->can($permission));
    }

    public function testOnlyTheOwnerAndAdministratorsGiveRoles(): void
    {
        self::assertSame(CompanyRole::cases(), CompanyRole::Owner->assignable());
        self::assertNotContains(CompanyRole::Owner, CompanyRole::Admin->assignable());
        self::assertSame([], CompanyRole::Billing->assignable());
        self::assertSame([], CompanyRole::Accountant->assignable());
    }
}
