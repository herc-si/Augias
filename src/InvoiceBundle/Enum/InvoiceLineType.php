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

namespace Augias\InvoiceBundle\Enum;

enum InvoiceLineType: string
{
    case INVOICE = 'invoice';
    case RECURRING_INVOICE = 'recurring_invoice';
    case CREDIT_NOTE = 'credit_note';
}
