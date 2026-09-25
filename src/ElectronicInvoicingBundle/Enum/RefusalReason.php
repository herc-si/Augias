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
 * Why a received invoice is refused — the normalised reason code (MDT-113) a
 * refusal must carry (BR-FR-CDV-15).
 *
 * Only these thirteen: the codes SUPER PDP's validator names as allowed for
 * status 210 (BR-FR-CDV-CL-09), as it listed them in the sandbox on
 * 25/09/2026. A refusal is for an invoice that cannot be processed — not
 * compliant, not for this company, a transaction nobody knows. A quantity or
 * a price the buyer disagrees with is a dispute (status 207), not a refusal,
 * and the platform turns such codes down here.
 */
enum RefusalReason: string
{
    case VatRate = 'TX_TVA_ERR';
    case Total = 'MONTANTTOTAL_ERR';
    case Calculation = 'CALCUL_ERR';
    case NotCompliant = 'NON_CONFORME';
    case Duplicate = 'DOUBLON';
    case BilledTwice = 'DOUBLE_FACT';
    case WrongRecipient = 'DEST_ERR';
    case UnknownTransaction = 'TRANSAC_INC';
    case UnknownIssuer = 'EMMET_INC';
    case ContractEnded = 'CONTRAT_TERM';
    case PurchaseOrder = 'CMD_ERR';
    case ContractReference = 'REF_CT_ABSENT';
    case ElectronicAddress = 'ADR_ERR';

    public function translationKey(): string
    {
        return 'einvoicing.refusal_reason.' . $this->value;
    }
}
