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

namespace Augias\BillBundle\Import;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * What a supplier's structured invoice says about itself, in major units.
 */
final readonly class SupplierInvoice
{
    public function __construct(
        public ?string $number,
        public ?DateTimeImmutable $issueDate,
        public ?DateTimeImmutable $dueDate,
        public string $currency,
        public BigDecimal $total,
        public ?BigDecimal $tax,
        public ?string $sellerName,
        /** The seller's SIREN or SIRET when given, else its VAT number. */
        public ?string $sellerIdentifier,
        /** EN 16931 type code: 380 an invoice, 381 a credit note. */
        public string $typeCode,
    ) {
    }

    public function isCreditNote(): bool
    {
        return '381' === $this->typeCode;
    }
}
