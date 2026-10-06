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

namespace Augias\ElectronicInvoicingBundle\Manager;

use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoicingProblem;
use Augias\ElectronicInvoicingBundle\Notification\ElectronicInvoicingProblemNotification;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\NotificationBundle\Notification\NotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the company when electronic invoicing goes wrong. Never fails what
 * raised it: an invoice that could not be sent is not made worse by an
 * e-mail that could not be either.
 */
final readonly class ElectronicInvoicingAlerts
{
    public function __construct(
        private NotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
    }

    public function raise(Company $company, ElectronicInvoicingProblem $problem, ?Invoice $invoice = null, ?string $detail = null): void
    {
        try {
            $this->notificationManager->sendNotification(new ElectronicInvoicingProblemNotification([
                'company' => $company,
                'problem' => $problem,
                'invoice' => $invoice,
                'detail' => null === $detail || '' === $detail ? null : $detail,
            ]));
        } catch (Throwable $e) {
            $this->logger->error('Failed to send electronic invoicing problem notification', [
                'company' => (string) $company->getId(),
                'problem' => $problem->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
