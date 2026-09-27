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

namespace Augias\AccountingBundle\Enum;

/**
 * Where a line of a bank statement stands against what the application knows.
 */
enum BankTransactionStatus: string
{
    /** Nothing in the application accounts for it yet. */
    case Unmatched = 'unmatched';

    /** Tied to the payment — received or paid out — it is the trace of. */
    case Matched = 'matched';

    /** Set aside on purpose: a transfer between own accounts, bank fees kept elsewhere. */
    case Ignored = 'ignored';
}
