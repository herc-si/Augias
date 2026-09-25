<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Enum;

/**
 * What a company reports about a sale that is not invoiced electronically —
 * to a private individual — for the tax administration's e-reporting.
 */
enum ReportKind: string
{
    /** The sale itself: amounts and VAT, by category. */
    case Transaction = 'transaction';

    /** Money received for services whose VAT falls due on payment. */
    case Payment = 'payment';
}
