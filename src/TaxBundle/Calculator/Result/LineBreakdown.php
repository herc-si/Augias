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

namespace Augias\TaxBundle\Calculator\Result;

use Augias\CoreBundle\Enum\SupplyType;
use Brick\Math\BigDecimal;

/**
 * Aggregated result of running the line-level tax calculator against a single line.
 *
 * - {@see $lineSubtotal} is the tax-exclusive line subtotal (price × qty, minus any
 *   inclusive-tax component that was extracted out of the gross figure).
 * - {@see $lineTotal} is the gross figure that should appear on the invoice/quote
 *   (subtotal + additive taxes).
 * - {@see $lineTax} is the sum of {@see TaxSummaryRow::$amount} across {@see $taxRows}.
 */
final readonly class LineBreakdown
{
    /**
     * @param list<TaxSummaryRow> $taxRows
     */
    public function __construct(
        public BigDecimal $lineSubtotal,
        public BigDecimal $lineTotal,
        public BigDecimal $lineTax,
        public array $taxRows,
        /**
         * Carried from the line so that whoever reads the tax off a document
         * can tell which part falls due on issue and which on payment.
         */
        public SupplyType $supplyType = SupplyType::Services,
    ) {
    }
}
