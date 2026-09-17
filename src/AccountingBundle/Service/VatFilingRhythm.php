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

namespace Augias\AccountingBundle\Service;

use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\CoreBundle\Entity\Company;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use function intdiv;

/**
 * Whether a company on the réel normal may still file its VAT quarterly.
 *
 * Monthly is the rule (CGI, art. 287-1). Quarterly is a tolerance, open while
 * the tax payable over the year runs under {@see self::QUARTERLY_CEILING}.
 *
 * The subtlety worth having a class for is *which* year. It is not the calendar
 * one: the threshold is assessed at the start of each quarter over the four
 * civil quarters behind it, so it moves with every quarter and can be crossed
 * in the middle of a year. Nothing else in this module measures a rolling
 * window — periods, declarations and ceilings all sit inside a calendar or
 * fiscal year — which is exactly why reading it off a period would be wrong.
 *
 * This answers the question; it does not change anyone's settings. The rhythm
 * stays the user's to choose, because the figure in the books is only part of
 * what they know.
 *
 * @see \Augias\AccountingBundle\Tests\Service\VatFilingRhythmTest
 */
final readonly class VatFilingRhythm
{
    /**
     * In minor units: 4 000 €.
     *
     * Deliberately not in the dated rate table. That file is the French
     * *micro-entreprise* table, and this is a rule of the réel normal; putting
     * it there would file it under a regime it does not belong to. If it ever
     * moves, it should get a dated table of its own rather than borrow that one.
     */
    public const int QUARTERLY_CEILING = 400_000;

    public function __construct(
        private LedgerEntryRepository $entries,
    ) {
    }

    /**
     * Net VAT — collected less deducted — over the four civil quarters before
     * the one containing the given date.
     *
     * Can come out negative, when more was reclaimed than charged. That is a
     * credit, and a company in that position is comfortably under the ceiling
     * rather than in any doubt about it.
     */
    public function taxOverTheTrailingYear(Company $company, DateTimeImmutable $on): BigInteger
    {
        $window = $this->trailingYear($on);

        // The same window the screen names, read from one place: a figure
        // measured over months other than the ones printed beside it would be
        // worse than no figure at all.
        $tax = $this->entries->taxForRange($company, $window['from'], $window['to']);

        $collected = BigInteger::zero();

        foreach ($tax['collected'] as $share) {
            $collected = $collected->plus($share['tax']);
        }

        return $collected->minus($tax['deducted']);
    }

    /**
     * Whether the quarterly tolerance is open on the given date.
     */
    public function mayFileQuarterly(Company $company, DateTimeImmutable $on): bool
    {
        return $this->taxOverTheTrailingYear($company, $on)
            ->isLessThan(BigInteger::of(self::QUARTERLY_CEILING));
    }

    /**
     * First day of the civil quarter the date falls in.
     */
    private function quarterStart(DateTimeImmutable $on): DateTimeImmutable
    {
        $month = intdiv((int) $on->format('n') - 1, 3) * 3 + 1;

        return $on->setDate((int) $on->format('Y'), $month, 1)->setTime(0, 0);
    }

    /**
     * The window the figure covers, for saying so on screen rather than leaving
     * the user to guess which twelve months are being counted.
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public function trailingYear(DateTimeImmutable $on): array
    {
        $currentQuarterStart = $this->quarterStart($on);

        return [
            'from' => $currentQuarterStart->modify('-1 year'),
            'to' => $currentQuarterStart->modify('-1 day'),
        ];
    }
}
