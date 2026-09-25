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
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicReport;
use Augias\ElectronicInvoicingBundle\Enum\ReportKind;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicReporterInterface;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicReportRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function implode;

/**
 * Reports what a company sold to private individuals — and was paid for it,
 * and credited and paid back — through the platform in use, for the tax
 * administration's e-reporting; and
 * marks the invoices it sent electronically as paid (fr:212) as the money
 * comes in, where the VAT falls due on payment.
 *
 * From the day the platform was set up, never before: e-reporting starts
 * when the company does it, and history is not the platform's to receive.
 * Each invoice and each payment is reported once; one that failed is tried
 * again the next time round. The platform aggregates and passes the data on
 * on its own schedule, so an hour's delay costs nothing.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Manager\ElectronicReportManagerTest
 */
final readonly class ElectronicReportManager
{
    private const array ISSUED = [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid];

    public function __construct(
        private ElectronicInvoiceProviderSettingRepository $settings,
        private ElectronicInvoiceProviderRegistry $providers,
        private ElectronicReportRepository $reports,
        private ReportDataBuilder $builder,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{reported: int, failed: int}
     */
    public function reportPending(Company $company): array
    {
        $setting = $this->settings->findActiveForCompany($company->getId());
        $reporter = $setting instanceof ElectronicInvoiceProviderSetting ? $this->providers->get($setting->getProvider()) : null;

        if (! $reporter instanceof ElectronicReporterInterface || ! $setting instanceof ElectronicInvoiceProviderSetting) {
            return ['reported' => 0, 'failed' => 0];
        }

        $since = $setting->getCreated() ?? new DateTimeImmutable('today');
        $stats = ['reported' => 0, 'failed' => 0];

        foreach ($this->invoicesToReport($company, $since) as $invoice) {
            $transactions = $this->builder->transactions($invoice);

            if ([] === $transactions) {
                continue;
            }

            $this->file($company, $setting, ReportKind::Transaction, $invoice->getId(), $stats, static fn (): array => $reporter->reportTransactions($setting->getSettings(), $transactions));
        }

        foreach ($this->paymentsToReport($company, $since) as $payment) {
            $data = $this->builder->payment($payment);

            if (null === $data) {
                continue;
            }

            $this->file($company, $setting, ReportKind::Payment, $payment->getId(), $stats, static fn (): array => $reporter->reportPayments($setting->getSettings(), [$data]));
        }

        foreach ($this->creditNotesToReport($company, $since) as $creditNote) {
            $transactions = $this->builder->transactions($creditNote);

            if ([] === $transactions) {
                continue;
            }

            $this->file($company, $setting, ReportKind::CreditNote, $creditNote->getId(), $stats, static fn (): array => $reporter->reportTransactions($setting->getSettings(), $transactions));
        }

        foreach ($this->refundsToReport($company, $since) as $allocation) {
            $data = $this->builder->refund($allocation);

            if (null === $data) {
                continue;
            }

            $this->file($company, $setting, ReportKind::Refund, $allocation->getId(), $stats, static fn (): array => $reporter->reportPayments($setting->getSettings(), [$data]));
        }

        foreach ($this->paymentsOnSentInvoices($company, $since, $setting->getProvider()) as [$payment, $invoiceReference]) {
            $data = $this->builder->payment($payment);

            if (null === $data) {
                continue;
            }

            $this->file($company, $setting, ReportKind::PaymentReceived, $payment->getId(), $stats, static fn (): array => $reporter->reportPaymentReceived($setting->getSettings(), $invoiceReference, $data));
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * @param array{reported: int, failed: int} $stats
     * @param callable(): list<string>          $send
     */
    private function file(Company $company, ElectronicInvoiceProviderSetting $setting, ReportKind $kind, ?Ulid $sourceId, array &$stats, callable $send): void
    {
        if (! $sourceId instanceof Ulid) {
            return;
        }

        $report = $this->reports->findForSource($company->getId(), $kind, $sourceId);

        if ($report instanceof ElectronicReport && $report->isSuccess()) {
            return;
        }

        if (! $report instanceof ElectronicReport) {
            $report = new ElectronicReport($kind, $sourceId, $setting->getProvider());
            $report->setCompany($company);
            $this->entityManager->persist($report);
        }

        try {
            $report->succeeded(implode(',', $send()));
            ++$stats['reported'];
        } catch (RuntimeException $e) {
            $report->failed($e->getMessage());
            ++$stats['failed'];
        }
    }

    /**
     * Issued invoices to private individuals, dated from the day reporting
     * started.
     *
     * @return iterable<Invoice>
     */
    private function invoicesToReport(Company $company, DateTimeImmutable $since): iterable
    {
        /** @var list<Invoice> $invoices */
        $invoices = $this->entityManager->getRepository(Invoice::class)->createQueryBuilder('i')
            ->andWhere('i.company = :company')
            ->andWhere('i.status IN (:issued)')
            ->andWhere('i.invoiceDate >= :since')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('issued', self::ISSUED)
            ->setParameter('since', $since->setTime(0, 0), 'date_immutable')
            ->getQuery()
            ->getResult();

        foreach ($invoices as $invoice) {
            if ($this->builder->isPrivateIndividual($invoice->getClient())) {
                yield $invoice;
            }
        }
    }

    /**
     * Credit notes issued to private individuals, dated from the day
     * reporting started — a draft has been handed to no one.
     *
     * @return iterable<CreditNote>
     */
    private function creditNotesToReport(Company $company, DateTimeImmutable $since): iterable
    {
        /** @var list<CreditNote> $creditNotes */
        $creditNotes = $this->entityManager->getRepository(CreditNote::class)->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.status IN (:issued)')
            ->andWhere('c.creditNoteDate >= :since')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('issued', [CreditNoteStatus::Issued, CreditNoteStatus::Settled])
            ->setParameter('since', $since->setTime(0, 0), 'date_immutable')
            ->getQuery()
            ->getResult();

        foreach ($creditNotes as $creditNote) {
            if ($this->builder->isPrivateIndividual($creditNote->getClient())) {
                yield $creditNote;
            }
        }
    }

    /**
     * Credit notes paid back to private individuals since reporting started.
     *
     * @return iterable<CreditNoteAllocation>
     */
    private function refundsToReport(Company $company, DateTimeImmutable $since): iterable
    {
        /** @var list<CreditNoteAllocation> $allocations */
        $allocations = $this->entityManager->getRepository(CreditNoteAllocation::class)->createQueryBuilder('a')
            ->andWhere('a.company = :company')
            ->andWhere('a.kind = :refund')
            ->andWhere('a.allocatedOn >= :since')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('refund', AllocationKind::Refund)
            ->setParameter('since', $since->setTime(0, 0), 'date_immutable')
            ->getQuery()
            ->getResult();

        foreach ($allocations as $allocation) {
            if ($this->builder->isPrivateIndividual($allocation->getCreditNote()->getClient())) {
                yield $allocation;
            }
        }
    }

    /**
     * Money received from private individuals since reporting started.
     *
     * @return iterable<Payment>
     */
    private function paymentsToReport(Company $company, DateTimeImmutable $since): iterable
    {
        foreach ($this->paymentsSince($company, $since) as $payment) {
            if ($this->builder->isPrivateIndividual($payment->getInvoice()?->getClient())) {
                yield $payment;
            }
        }
    }

    /**
     * Money received since reporting started on invoices that went out
     * through the provider in use, with the provider's id for each invoice:
     * the status goes on that invoice, where the other party sees it too.
     *
     * @return iterable<array{0: Payment, 1: string}>
     */
    private function paymentsOnSentInvoices(Company $company, DateTimeImmutable $since, string $provider): iterable
    {
        foreach ($this->paymentsSince($company, $since) as $payment) {
            $reference = null;

            foreach ($payment->getInvoice()?->getElectronicInvoiceSubmissions() ?? [] as $submission) {
                if ($submission->isSuccess() && $provider === $submission->getProvider() && null !== $submission->getExternalReference()) {
                    $reference = $submission->getExternalReference();
                }
            }

            if (null !== $reference) {
                yield [$payment, $reference];
            }
        }
    }

    /**
     * @return list<Payment>
     */
    private function paymentsSince(Company $company, DateTimeImmutable $since): array
    {
        /** @var list<Payment> $payments */
        $payments = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p')
            ->andWhere('p.company = :company')
            ->andWhere('p.status = :captured')
            ->andWhere('p.completed >= :since')
            ->andWhere('p.invoice IS NOT NULL')
            ->setParameter('company', $company->getId(), UlidType::NAME)
            ->setParameter('captured', PaymentStatus::Captured)
            ->setParameter('since', $since->setTime(0, 0))
            ->getQuery()
            ->getResult();

        return $payments;
    }
}
