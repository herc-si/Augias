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
 * What the next declaration of a kind will carry, as the platform has
 * aggregated it so far: one row per day, and per category for sales.
 */
final readonly class ReportPreview
{
    /**
     * @param 'transaction'|'payment' $kind
     * @param list<ReportPreviewRow>  $rows
     * @param int                     $invoices invoices declared one by one (sales abroad)
     */
    public function __construct(
        public string $kind,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public array $rows,
        public int $invoices = 0,
    ) {
    }
}
