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
 * A day of a preview: for sales, a category with its amount before tax and
 * its VAT; for payments, the amount received at each VAT rate.
 */
final readonly class ReportPreviewRow
{
    /**
     * @param array<string, string> $byRate amount by VAT rate, payments only
     */
    public function __construct(
        public DateTimeImmutable $date,
        public ?string $category = null,
        public ?string $taxExclusiveAmount = null,
        public ?string $taxTotal = null,
        public array $byRate = [],
    ) {
    }
}
