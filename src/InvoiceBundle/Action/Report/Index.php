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

namespace Augias\InvoiceBundle\Action\Report;

use Augias\InvoiceBundle\Report\AgedReceivables;
use Augias\InvoiceBundle\Report\RevenueByClient;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use function array_map;
use function implode;
use function max;
use function min;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * The reports page: the aged receivables and the revenue per client, on
 * screen or as a CSV file for a spreadsheet.
 *
 * Both are read straight from the documents: nothing is stored, and what the
 * page shows is what the invoices, credit notes and payments say right now.
 */
final readonly class Index
{
    public const string AGED = 'aged';

    public const string REVENUE = 'revenue';

    /** The first invoice of an installation cannot be older than this. */
    private const int FIRST_YEAR = 2000;

    public function __construct(
        private AgedReceivables $agedReceivables,
        private RevenueByClient $revenueByClient,
        private ClockInterface $clock,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, string $report = self::AGED, string $_format = 'html'): Response
    {
        $today = $this->clock->now();
        $thisYear = (int) $today->format('Y');
        $year = max(self::FIRST_YEAR, min($thisYear, $request->query->getInt('year', $thisYear)));

        $tables = match ($report) {
            self::AGED => $this->agedReceivables->asOf($today),
            self::REVENUE => $this->revenueByClient->forYear($year),
            default => throw new NotFoundHttpException(),
        };

        if ('csv' === $_format) {
            return $this->csv($report, $tables, self::AGED === $report ? $today->format('Y-m-d') : (string) $year);
        }

        return new Response($this->twig->render('@AugiasInvoice/Report/index.html.twig', [
            'report' => $report,
            'tables' => $tables,
            'today' => $today,
            'year' => $year,
            'thisYear' => $thisYear,
            'buckets' => AgedReceivables::BUCKETS,
        ]));
    }

    /**
     * Semicolons, a decimal comma and a byte order mark: what Excel opens in
     * columns, with its accents, on a French machine.
     *
     * @param array<string, array<string, mixed>> $tables
     */
    private function csv(string $report, array $tables, string $suffix): Response
    {
        $t = fn (string $key): string => $this->translator->trans('report.' . $key);

        $lines = [self::AGED === $report
            ? [$t('column.client'), $t('column.currency'), $t('aged.not_due'), $t('aged.csv.d1_30'), $t('aged.csv.d31_60'), $t('aged.csv.d61_90'), $t('aged.csv.over_90'), $t('column.total')]
            : [$t('column.client'), $t('column.currency'), $t('revenue.invoices'), $t('revenue.invoiced'), $t('revenue.credited'), $t('revenue.net'), $t('revenue.received')]];

        foreach ($tables as $currency => $table) {
            foreach ($table['rows'] as $row) {
                $lines[] = self::AGED === $report
                    ? [$row['client'], $currency, ...array_map($this->amount(...), $row['buckets']), $this->amount($row['total'])]
                    : [$row['client'], $currency, (string) $row['invoices'], $this->amount($row['invoiced']), $this->amount($row['credited']), $this->amount($row['net']), $this->amount($row['received'])];
            }
        }

        $csv = "\xEF\xBB\xBF";

        foreach ($lines as $line) {
            $csv .= implode(';', array_map(
                static fn (string $cell): string => str_contains($cell, ';') || str_contains($cell, '"') ? '"' . str_replace('"', '""', $cell) . '"' : $cell,
                $line,
            )) . "\r\n";
        }

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('%s-%s.csv', self::AGED === $report ? 'balance-agee' : 'chiffre-affaires-par-client', $suffix)),
        ]);
    }

    /**
     * Minor units to a plain decimal a spreadsheet reads as a number: "1234,56".
     */
    private function amount(BigInteger $minor): string
    {
        return str_replace('.', ',', (string) $minor->toBigDecimal()->dividedBy(100, 2, RoundingMode::HalfEven));
    }
}
