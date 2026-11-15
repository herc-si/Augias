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

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use function array_fill;
use function array_values;
use function ksort;
use function strcasecmp;
use function usort;

/**
 * Who owes what, and for how long: the unpaid balance of every issued
 * invoice, per client, sorted into how late it is.
 *
 * Lateness counts from the day after the due date, like the "overdue" status
 * does. An invoice without a due date is never late.
 *
 * Amounts in different currencies cannot be added, so there is one table per
 * currency.
 *
 * @see \Augias\InvoiceBundle\Tests\Report\ReportsTest
 */
final readonly class AgedReceivables
{
    /** Upper bounds, in days late, of every bucket but the last. */
    public const array BUCKETS = [0, 30, 60, 90];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, array{rows: list<array{clientId: string, client: string, buckets: list<BigInteger>, total: BigInteger}>, totals: list<BigInteger>, total: BigInteger}> keyed by currency code
     */
    public function asOf(DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);

        /** @var list<array{balance: mixed, due: DateTimeInterface|null, clientId: mixed, client: string|null, currencyCode: string|null}> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i.balance AS balance', 'i.due AS due', 'c.id AS clientId', 'c.name AS client', 'c.currencyCode AS currencyCode')
            ->from(Invoice::class, 'i')
            ->innerJoin('i.client', 'c')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value])
            ->getQuery()
            ->getArrayResult();

        $report = [];
        $count = count(self::BUCKETS) + 1;

        foreach ($invoices as $invoice) {
            $balance = BigInteger::of((string) $invoice['balance']);
            $currency = (string) $invoice['currencyCode'];

            if ($balance->isNegativeOrZero() || '' === $currency) {
                continue;
            }

            $clientId = (string) $invoice['clientId'];
            $bucket = $this->bucket($invoice['due'], $today);

            $report[$currency] ??= ['rows' => [], 'totals' => array_fill(0, $count, BigInteger::zero()), 'total' => BigInteger::zero()];
            $report[$currency]['rows'][$clientId] ??= ['clientId' => $clientId, 'client' => (string) $invoice['client'], 'buckets' => array_fill(0, $count, BigInteger::zero()), 'total' => BigInteger::zero()];

            $row = &$report[$currency]['rows'][$clientId];
            $row['buckets'][$bucket] = $row['buckets'][$bucket]->plus($balance);
            $row['total'] = $row['total']->plus($balance);
            unset($row);

            $report[$currency]['totals'][$bucket] = $report[$currency]['totals'][$bucket]->plus($balance);
            $report[$currency]['total'] = $report[$currency]['total']->plus($balance);
        }

        foreach ($report as $currency => $table) {
            $rows = array_values($table['rows']);
            // The largest debt first: that is the one to chase.
            usort($rows, static fn (array $a, array $b): int => $b['total']->compareTo($a['total']) ?: strcasecmp($a['client'], $b['client']));
            $report[$currency]['rows'] = $rows;
        }

        ksort($report);

        return $report;
    }

    /**
     * 0 = not due yet, then one bucket per bound of {@see BUCKETS}, the last one open-ended.
     */
    private function bucket(?DateTimeInterface $due, DateTimeImmutable $today): int
    {
        if (! $due instanceof DateTimeInterface) {
            return 0;
        }

        $due = DateTimeImmutable::createFromInterface($due)->setTime(0, 0);

        if ($due >= $today) {
            return 0;
        }

        $late = (int) $due->diff($today)->days;

        foreach (self::BUCKETS as $index => $bound) {
            if ($index > 0 && $late <= $bound) {
                return $index;
            }
        }

        return count(self::BUCKETS);
    }
}
