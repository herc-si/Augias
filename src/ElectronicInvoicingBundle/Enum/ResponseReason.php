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
 * Why a received invoice is refused or disputed — the normalised reason code
 * (MDT-113) either answer must carry (BR-FR-CDV-15).
 *
 * A refusal takes the first thirteen only: the codes SUPER PDP's validator
 * names as allowed for status 210 (BR-FR-CDV-CL-09), as it listed them in the
 * sandbox on 25/09/2026. A refusal is for an invoice that cannot be processed
 * — not compliant, not for this company, a transaction nobody knows.
 *
 * A dispute (status 207) takes those and the rest: a price, a quantity, a
 * delivery the buyer disagrees with — the invoice stands, but not as it is.
 * Every code below was accepted for 207 in the sandbox on 25/09/2026.
 */
enum ResponseReason: string
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

    case UnitPrice = 'PU_ERR';
    case Quantity = 'QTE_ERR';
    case Discount = 'REM_ERR';
    case Item = 'ART_ERR';
    case IncompleteDelivery = 'LIVR_INCOMP';
    case Quality = 'QUALITE_ERR';
    case PaymentMethod = 'MODPAI_ERR';
    case BankDetails = 'COORD_BANC_ERR';
    case Reference = 'REF_ERR';
    case Siret = 'SIRET_ERR';
    case RoutingCode = 'CODE_ROUTAGE_ERR';
    case Other = 'AUTRE';

    public function translationKey(): string
    {
        return 'einvoicing.response_reason.' . $this->value;
    }

    public function isAllowedFor(ReceiptResponse $response): bool
    {
        return match ($response) {
            ReceiptResponse::Accepted => false,
            ReceiptResponse::Disputed => true,
            ReceiptResponse::Refused => $this->isRefusal(),
        };
    }

    /**
     * One of the thirteen a refusal can carry.
     */
    public function isRefusal(): bool
    {
        return match ($this) {
            self::UnitPrice, self::Quantity, self::Discount, self::Item, self::IncompleteDelivery, self::Quality,
            self::PaymentMethod, self::BankDetails, self::Reference, self::Siret, self::RoutingCode, self::Other => false,
            default => true,
        };
    }
}
