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

namespace Augias\AccountingBundle\Tests\Regime\Fr;

use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReelNormalRegime::class)]
final class ReelNormalRegimeTest extends TestCase
{
    /**
     * Unlike the micro regime, where the purchase register follows the activity,
     * VAT here is reclaimed on every kind of purchase — so a pure services
     * business needs the register just as much as a reseller does.
     */
    public function testBothBooksAreKeptWhateverTheActivity(): void
    {
        foreach (ActivityNature::cases() as $nature) {
            self::assertSame(
                [LedgerBook::Revenue, LedgerBook::Purchase],
                new ReelNormalRegime()->books($this->profile($nature)),
            );
        }
    }

    /**
     * A company filing a CA3 is past every limit the micro regime measures
     * against, so there is nothing left to warn it about. An empty set rather
     * than null, which is what {@see \Augias\AccountingBundle\Model\ThresholdSet}
     * asks of a regime with no limits.
     */
    public function testThereIsNoCeilingLeftToMeasureAgainst(): void
    {
        $thresholds = new ReelNormalRegime()->thresholds($this->profile(), new DateTimeImmutable('2026-12-31'));

        self::assertCount(0, $thresholds);
        self::assertSame([], $thresholds->all());
    }

    /**
     * Monthly is the rule, quarterly the tolerance under 4 000 € of tax a year.
     * Annual is not on offer: the CA12 that used to justify it is abolished on
     * 1 January 2027.
     */
    public function testVatIsDeclaredMonthlyOrQuarterlyAndNeverYearly(): void
    {
        $periodicities = new ReelNormalRegime()->declarationPeriodicities();

        self::assertSame([PeriodType::Month, PeriodType::Quarter], $periodicities);
        self::assertNotContains(PeriodType::Year, $periodicities);
    }

    /**
     * The point of the regime — a company that chose it charges VAT.
     */
    public function testACompanyOnThisRegimeIsInScopeForVat(): void
    {
        self::assertFalse(new ReelNormalRegime()->isVatExemptByDefault());
    }

    /**
     * The regime produces no return of its own. Contributions under the réel
     * are assessed on profit, which these cash-basis books do not compute, and
     * a turnover return with nothing in it would be worse than none at all.
     */
    public function testTheRegimeOwesNoReturnOfItsOwn(): void
    {
        self::assertSame([], new ReelNormalRegime()->declarationKinds());
    }

    /**
     * Reached only by a caller that asks directly. It answers with the turnover
     * and says why there is nothing derived from it, rather than inventing a
     * charge.
     */
    public function testCalculatingReportsTurnoverAndNoCharges(): void
    {
        $turnover = new TurnoverSummary(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-03-31'),
            'EUR',
            [ActivityNature::ServicesBic->value => BigInteger::of(1_234_56)],
        );

        $result = new ReelNormalRegime()->calculate($turnover, $this->profile(), new AccountingPeriod());

        self::assertSame('123456', (string) $result->turnover);
        self::assertSame([], $result->lines);
        self::assertTrue($result->totalDue()->isZero());
        self::assertCount(1, $result->warnings);
        self::assertSame('accounting.regime.fr_reel_normal.contributions_not_computed', $result->warnings[0]);
    }

    private function profile(ActivityNature $activity = ActivityNature::ServicesBic): AccountingProfile
    {
        return new AccountingProfile(
            regimeCode: ReelNormalRegime::CODE,
            vatExempt: false,
            vatExemptMention: '',
            activityStartDate: null,
            primaryActivity: $activity,
            declarationPeriodicity: PeriodType::Month,
            vatPeriodicity: null,
            fiscalYearStartMonth: 1,
            currencyCode: 'EUR',
        );
    }
}
