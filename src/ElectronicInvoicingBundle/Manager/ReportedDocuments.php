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

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Entity\BillPayment;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicReport;
use Augias\ElectronicInvoicingBundle\Enum\ReportKind;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\PaymentBundle\Entity\Payment;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The document behind each e-reporting record, to link it: the invoice for
 * a sale or a payment on it, the credit note for a credit or a refund of it,
 * the purchase for a payment sent.
 */
final readonly class ReportedDocuments
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function invoiceFor(ElectronicReport $report): ?Invoice
    {
        return match ($report->getKind()) {
            ReportKind::Transaction => $this->entityManager->find(Invoice::class, $report->getSourceId()),
            ReportKind::Payment, ReportKind::PaymentReceived => $this->entityManager->find(Payment::class, $report->getSourceId())?->getInvoice(),
            default => null,
        };
    }

    public function creditNoteFor(ElectronicReport $report): ?CreditNote
    {
        return match ($report->getKind()) {
            ReportKind::CreditNote => $this->entityManager->find(CreditNote::class, $report->getSourceId()),
            ReportKind::Refund => $this->entityManager->find(CreditNoteAllocation::class, $report->getSourceId())?->getCreditNote(),
            default => null,
        };
    }

    public function billFor(ElectronicReport $report): ?Bill
    {
        return ReportKind::PaymentSent === $report->getKind()
            ? $this->entityManager->find(BillPayment::class, $report->getSourceId())?->getBill()
            : null;
    }
}
