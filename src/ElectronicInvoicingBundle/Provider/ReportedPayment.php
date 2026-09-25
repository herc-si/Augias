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
 * Money received from a private individual for services whose VAT falls due
 * on payment. Amounts in major units, tax included, by VAT rate.
 */
final readonly class ReportedPayment
{
    /**
     * @param array<string, string> $amounts by VAT rate ("20.00")
     */
    public function __construct(
        public DateTimeImmutable $date,
        public string $currency,
        public array $amounts,
    ) {
    }
}
