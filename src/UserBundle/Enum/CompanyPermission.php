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

/**
 * What a member of a company may do there. The values are the attributes the
 * voter answers for — `is_granted('company.billing.write')` in a template.
 *
 * Reading is not split by area: every member sees the company's documents
 * and its books. What differs between roles is what they may change.
 */
enum CompanyPermission: string
{
    /** Clients, quotes, invoices, credit notes, payments, purchases — to see. */
    case BillingRead = 'company.billing.read';

    /** The same, to create, change, send or delete. */
    case BillingWrite = 'company.billing.write';

    /** Close a period, record a declaration, write an entry by hand. */
    case AccountingWrite = 'company.accounting.write';

    /** Download the company's data, and the accounting exports. */
    case Export = 'company.export';

    /** Company settings, taxes, payment methods, e-invoicing platform. */
    case Settings = 'company.settings';

    /** Invite people, change their role, take their access away. */
    case ManageMembers = 'company.members';

    /** Close the company and hand it over to another owner. */
    case CloseCompany = 'company.close';
}
