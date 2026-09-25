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

use DateTimeImmutable;

/**
 * One sale to a private individual, in one category — what e-reporting
 * declares instead of an e-invoice. Amounts in major units, as decimals.
 */
final readonly class ReportedTransaction
{
    /**
     * @param array<string, array{taxable: string, tax: string}> $subtotals by VAT rate ("20.00")
     */
    public function __construct(
        public DateTimeImmutable $date,
        public string $currency,
        /** TLB1 goods, TPS1 services, TNT1 not subject to VAT (franchise). */
        public string $category,
        public string $taxExclusiveAmount,
        public string $taxTotal,
        public array $subtotals,
        /** UNTDID 2005: 3 invoice date, 35 delivery, 432 payment. */
        public ?string $taxDueDateTypeCode = null,
    ) {
    }
}
