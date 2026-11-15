<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\InvoiceBundle\Report;

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Repository\PaymentRepository;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use function array_values;
use function ksort;
use function sprintf;
use function strcasecmp;
use function usort;

/**
 * What each client brought in over a calendar year, three ways:
 *
 *  - invoiced: the invoices issued in the year, before tax and without the
 *    disbursements passed on at cost (they are not revenue);
 *  - credited: the credit notes issued in the year, on the same basis;
 *  - received: the payments taken in the year, tax included — money in the
 *    bank, which is the figure a business declaring on receipts looks at.
 *
 * Drafts and cancelled invoices are left out: only what was issued counts.
 * One table per currency, as amounts in different currencies cannot be added.
 *
 * @see \Augias\InvoiceBundle\Tests\Report\ReportsTest
 */
final readonly class RevenueByClient
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentRepository $payments,
    ) {
    }

    /**
     * @return array<string, array{rows: list<array{clientId: string, client: string, invoices: int, invoiced: BigInteger, credited: BigInteger, net: BigInteger, received: BigInteger}>, invoiced: BigInteger, credited: BigInteger, net: BigInteger, received: BigInteger}> keyed by currency code
     */
    public function forYear(int $year): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $to = new DateTimeImmutable(sprintf('%04d-12-31 00:00:00', $year));

        $report = [];

        $invoices = $this->entityManager->createQueryBuilder()
            ->select('c.id AS clientId', 'c.name AS client', 'c.currencyCode AS currencyCode', 'COUNT(i.id) AS documents', 'SUM(i.total) AS total', 'SUM(i.tax) AS tax', 'SUM(i.disbursementTotal) AS disbursements')
            ->from(Invoice::class, 'i')
            ->innerJoin('i.client', 'c')
            ->where('i.status NOT IN (:excluded)')
            ->andWhere('i.invoiceDate BETWEEN :from AND :to')
            ->setParameter('excluded', [InvoiceStatus::New->value, InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            // The column is a DATE: bound as one, or SQLite compares it as text against a date-time.
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->groupBy('c.id', 'c.name', 'c.currencyCode')
            ->getQuery()
            ->getArrayResult();

        foreach ($invoices as $line) {
            $row = &$this->row($report, $line);
            $row['invoices'] = (int) $line['documents'];
            $row['invoiced'] = $this->beforeTax($line);
            unset($row);
        }

        $creditNotes = $this->entityManager->createQueryBuilder()
            ->select('c.id AS clientId', 'c.name AS client', 'c.currencyCode AS currencyCode', 'SUM(n.total) AS total', 'SUM(n.tax) AS tax', 'SUM(n.disbursementTotal) AS disbursements')
            ->from(CreditNote::class, 'n')
            ->innerJoin('n.client', 'c')
            ->where('n.status IN (:issued)')
            ->andWhere('n.creditNoteDate BETWEEN :from AND :to')
            ->setParameter('issued', [CreditNoteStatus::Issued->value, CreditNoteStatus::Settled->value])
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->groupBy('c.id', 'c.name', 'c.currencyCode')
            ->getQuery()
            ->getArrayResult();

        foreach ($creditNotes as $line) {
            $row = &$this->row($report, $line);
            $row['credited'] = $this->beforeTax($line);
            unset($row);
        }

        foreach ($this->payments->getIncomeByClientBetween($from, $to->setTime(23, 59, 59)) as $line) {
            $row = &$this->row($report, $line);
            $row['received'] = BigInteger::of((string) $line['total']);
            unset($row);
        }

        foreach ($report as $currency => $table) {
            $rows = array_values($table['rows']);

            foreach ($rows as &$row) {
                $row['net'] = $row['invoiced']->minus($row['credited']);

                foreach (['invoiced', 'credited', 'net', 'received'] as $column) {
                    $table[$column] = $table[$column]->plus($row[$column]);
                }
            }

            unset($row);

            usort($rows, static fn (array $a, array $b): int => $b['net']->compareTo($a['net']) ?: strcasecmp($a['client'], $b['client']));
            $table['rows'] = $rows;
            $report[$currency] = $table;
        }

        ksort($report);

        return $report;
    }

    /**
     * @param array<string, mixed>                $report
     * @param array<string, mixed>                $line
     *
     * @return array<string, mixed>
     */
    private function &row(array &$report, array $line): array
    {
        $currency = (string) $line['currencyCode'];
        $clientId = (string) $line['clientId'];
        $zero = BigInteger::zero();

        $report[$currency] ??= ['rows' => [], 'invoiced' => $zero, 'credited' => $zero, 'net' => $zero, 'received' => $zero];
        $report[$currency]['rows'][$clientId] ??= ['clientId' => $clientId, 'client' => (string) $line['client'], 'invoices' => 0, 'invoiced' => $zero, 'credited' => $zero, 'net' => $zero, 'received' => $zero];

        return $report[$currency]['rows'][$clientId];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function beforeTax(array $line): BigInteger
    {
        return BigInteger::of((string) $line['total'])
            ->minus(BigInteger::of((string) ($line['tax'] ?? 0)))
            ->minus(BigInteger::of((string) ($line['disbursements'] ?? 0)));
    }
}
