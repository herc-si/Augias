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

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use Augias\ElectronicInvoicingBundle\Provider\ReportPreview;
use Augias\ElectronicInvoicingBundle\Provider\ReportPreviewRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Reads SUPER PDP's preview of the next declaration — the XML report as it
 * would go to the tax administration — down to what a person checks: the
 * period, and the amounts by day.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp\EreportingPreviewParserTest
 */
final class EreportingPreviewParser
{
    /**
     * Null for an empty body (nothing in the period yet) or one that is not a
     * report.
     *
     * @param 'transaction'|'payment' $kind
     */
    public function parse(string $kind, string $xml): ?ReportPreview
    {
        if ('' === trim($xml)) {
            return null;
        }

        try {
            $report = new SimpleXMLElement($xml, LIBXML_NONET);
        } catch (Throwable) {
            return null;
        }

        $section = 'payment' === $kind ? $report->PaymentsReport : $report->TransactionsReport;

        if (0 === $section->count()) {
            return null;
        }

        $start = $this->date((string) $section->ReportPeriod->StartDate);
        $end = $this->date((string) $section->ReportPeriod->EndDate);

        if (null === $start || null === $end) {
            return null;
        }

        $rows = [];

        if ('payment' === $kind) {
            foreach ($section->Transactions->Payment as $payment) {
                $date = $this->date((string) $payment->Date);

                if (null === $date) {
                    continue;
                }

                $byRate = [];

                foreach ($payment->SubTotals as $subtotal) {
                    $byRate[$this->amount((string) $subtotal->TaxPercent)] = $this->amount((string) $subtotal->Amount);
                }

                $rows[] = new ReportPreviewRow($date, byRate: $byRate);
            }
        } else {
            foreach ($section->Transactions as $transaction) {
                $date = $this->date((string) $transaction->Date);

                if (null === $date) {
                    continue;
                }

                $rows[] = new ReportPreviewRow(
                    $date,
                    (string) $transaction->CategoryCode,
                    $this->amount((string) $transaction->TaxExclusiveAmount),
                    $this->amount((string) $transaction->TaxTotal),
                );
            }
        }

        return new ReportPreview($kind, $start, $end, $rows, 'payment' === $kind ? 0 : $section->Invoice->count());
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Ymd', trim($value));

        return false === $date ? null : $date;
    }

    private function amount(string $value): string
    {
        try {
            return BigDecimal::of(trim($value))->toScale(2, RoundingMode::HalfEven)->__toString();
        } catch (Throwable) {
            return '0.00';
        }
    }
}
