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

use RuntimeException;

/**
 * A provider that files e-reporting data — sales to private individuals and
 * the payments for them — which it aggregates and passes on to the tax
 * administration on the schedule the company's VAT regime sets.
 */
interface ElectronicReporterInterface
{
    /**
     * @param array<string, mixed>       $config
     * @param list<ReportedTransaction> $transactions
     *
     * @return list<string> the platform's ids for what it stored
     *
     * @throws RuntimeException
     */
    public function reportTransactions(array $config, array $transactions): array;

    /**
     * @param array<string, mixed>   $config
     * @param list<ReportedPayment> $payments
     *
     * @return list<string>
     *
     * @throws RuntimeException
     */
    public function reportPayments(array $config, array $payments): array;
}
