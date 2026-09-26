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

namespace Augias\UserBundle\Enum;

use function in_array;

/**
 * The part someone plays in a company, and so what they may do there.
 *
 * Four fixed roles rather than rights ticked one by one: each can be said in a
 * sentence, and that sentence is what the members page, the help and the terms
 * show. A company always has an owner, and only an owner can close it or make
 * someone else the owner.
 */
enum CompanyRole: string
{
    /** Everything, including closing the company and handing it over. */
    case Owner = 'owner';

    /** Everything else: settings, members (never the owner), billing, books. */
    case Admin = 'admin';

    /** Day-to-day billing: clients, quotes, invoices, payments, purchases. */
    case Billing = 'billing';

    /** Reads everything, keeps the books, exports — changes no document. */
    case Accountant = 'accountant';

    /**
     * @return list<CompanyPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => CompanyPermission::cases(),
            self::Admin => [
                CompanyPermission::BillingRead,
                CompanyPermission::BillingWrite,
                CompanyPermission::AccountingWrite,
                CompanyPermission::Export,
                CompanyPermission::Settings,
                CompanyPermission::ManageMembers,
            ],
            self::Billing => [
                CompanyPermission::BillingRead,
                CompanyPermission::BillingWrite,
            ],
            self::Accountant => [
                CompanyPermission::BillingRead,
                CompanyPermission::AccountingWrite,
                CompanyPermission::Export,
            ],
        };
    }

    public function can(CompanyPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function labelKey(): string
    {
        return 'users.role.' . $this->value;
    }

    /**
     * The roles someone may be given by a member playing this one: an owner
     * gives any, an administrator any but owner, the others none.
     *
     * @return list<self>
     */
    public function assignable(): array
    {
        return match ($this) {
            self::Owner => self::cases(),
            self::Admin => [self::Admin, self::Billing, self::Accountant],
            default => [],
        };
    }
}
