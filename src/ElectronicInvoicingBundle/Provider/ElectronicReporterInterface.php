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
 * the payments for them, and payments on invoices sent through it — which it
 * passes on to the tax administration.
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

    /**
     * Marks an invoice sent through the provider as paid, for the amount
     * received — the status the tax administration reads the VAT due on
     * payment from.
     *
     * @param array<string, mixed> $config
     * @param string               $invoiceReference the provider's id for the invoice sent
     *
     * @return list<string> the provider's id for the status sent
     *
     * @throws RuntimeException
     */
    public function reportPaymentReceived(array $config, string $invoiceReference, ReportedPayment $payment): array;
}
