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

namespace Augias\ElectronicInvoicingBundle\Provider;

use Augias\CoreBundle\Enum\SupplyType;
use Brick\Math\BigNumber;
use DateTimeImmutable;

/**
 * One invoice a provider reports as received by this company — the inbound
 * counterpart of {@see ElectronicInvoiceSubmissionResult}. Providers map their
 * own wire format to this shape; nothing downstream needs to know which
 * provider it came from beyond the `provider` name already tracked on
 * {@see \Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt}.
 */
final readonly class ReceivedElectronicInvoiceData
{
    public function __construct(
        /**
         * The provider's own id for this invoice — used as the de-duplication
         * key so importing runs already-seen invoices back through are skipped.
         */
        public string $externalReference,
        public ?string $invoiceNumber,
        public ?string $sellerName,
        /**
         * The seller's SIREN/SIRET or other business identifier, as reported by
         * the provider — kept as free text since providers report varying
         * identifier schemes.
         */
        public ?string $sellerIdentifier,
        public ?DateTimeImmutable $issueDate,
        /**
         * Minor units (cents), matching every other Money amount in this app.
         */
        public ?BigNumber $totalAmount,
        public ?string $currencyCode,
        public ?string $statusCode,
        /**
         * The VAT the supplier charged (BT-110), minor units — null when the
         * invoice does not say.
         */
        public ?BigNumber $taxAmount = null,
        /**
         * Goods or services, from the billing framework (BT-23). Null for a
         * mixed invoice: no single answer, and the bill keeps its default.
         */
        public ?SupplyType $supplyType = null,
        /**
         * Whether the supplier's VAT falls due on the invoice date (BT-8) —
         * its option for VAT on debits.
         */
        public bool $supplierVatOnDebits = false,
    ) {
    }
}
