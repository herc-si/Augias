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

namespace Augias\AccountingBundle\Fec;

/**
 * Which list of fields the FEC carries (LPF art. A47 A-1).
 */
enum FecVariant: string
{
    /** Section VII: the eighteen fields every accounting keeps. */
    case Standard = 'standard';

    /**
     * Section VIII: a professional in BNC keeping cash accounting — the
     * eighteen, plus when and how each amount was settled, the nature of the
     * operation and who it was with.
     */
    case CashBnc = 'cash_bnc';

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = [
            'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
            'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
            'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
        ];

        return self::CashBnc === $this ? [...$fields, 'DateRglt', 'ModeRglt', 'NatOp', 'IdClient'] : $fields;
    }
}
