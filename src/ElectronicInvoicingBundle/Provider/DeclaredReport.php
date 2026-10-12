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
 * One e-reporting declaration the platform built and sent to the tax
 * administration: a period's sales, or its payments, and where it stands.
 */
final readonly class DeclaredReport
{
    /**
     * @param 'transaction'|'payment' $kind
     */
    public function __construct(
        public string $externalId,
        public string $kind,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public ?string $status,
        public ?DateTimeImmutable $statusAt,
    ) {
    }
}
