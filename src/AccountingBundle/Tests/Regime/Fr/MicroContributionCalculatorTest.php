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
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\DeclarationLine;
use Augias\AccountingBundle\Model\DeclarationResult;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\Fr\FrenchRateTable;
use Augias\AccountingBundle\Regime\Fr\MicroContributionCalculator;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function array_column;
use function array_filter;
use function array_values;
use function dirname;

#[CoversClass(MicroContributionCalculator::class)]
final class MicroContributionCalculatorTest extends TestCase
{
    private const int TEN_THOUSAND_EUROS = 1_000_000;

    public function testChargesSocialContributionsAndTrainingLevy(): void
    {
        $result = $this->calculate(
            $this->turnover([ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS]),
            $this->profile(),
        );

        $keys = array_column($result->lines, 'key');
        self::assertSame(['social.services_bic', 'training.services_bic'], $keys);

        $social = $this->line($result, 'social.services_bic');
        // 10 000 EUR at 21.2%.
        self::assertSame('21.2', (string) $social->rate);
        self::assertSame('212000', (string) $social->amount);
        self::assertSame(DeclarationLine::KIND_CONTRIBUTION, $social->kind);
        self::assertSame('accounting.declaration.line.social', $social->labelKey);

        $training = $this->line($result, 'training.services_bic');
        // 10 000 EUR at 0.3% — 30 EUR, not 3 000.
        self::assertSame('3000', (string) $training->amount);
        self::assertSame(DeclarationLine::KIND_LEVY, $training->kind);

        self::assertSame('215000', (string) $result->totalContributions());
        self::assertSame('215000', (string) $result->totalDue());
        self::assertTrue($result->totalIncomeTax()->isZero());
        self::assertSame('1000000', (string) $result->turnover);
    }

    /**
     * Without the option, income tax is settled on the annual return and must
     * not appear here at all.
     */
    public function testFlatRateIncomeTaxOnlyAppliesWhenOptedIn(): void
    {
        $turnover = $this->turnover([ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS]);

        $without = $this->calculate($turnover, $this->profile());
        self::assertNotContains('income_tax.services_bic', array_column($without->lines, 'key'));

        $with = $this->calculate($turnover, $this->profile(['income_tax_option' => '1']));
        $line = $this->line($with, 'income_tax.services_bic');

        self::assertSame('1.7', (string) $line->rate);
        self::assertSame('17000', (string) $line->amount);
        self::assertSame(DeclarationLine::KIND_INCOME_TAX, $line->kind);

        // It is owed on top of the contributions, but is not one of them.
        self::assertSame('215000', (string) $with->totalContributions());
        self::assertSame('17000', (string) $with->totalIncomeTax());
        self::assertSame('232000', (string) $with->totalDue());
    }

    public function testAcreHalvesTheSocialRateWithinItsWindow(): void
    {
        $result = $this->calculate(
            $this->turnover([ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS]),
            $this->profile(
                ['acre' => '1'],
                new DateTimeImmutable('2026-01-15'),
            ),
            $this->period('2026-04-01', '2026-06-30'),
        );

        $social = $this->line($result, 'social.services_bic');

        self::assertSame('10.6000', (string) $social->rate);
        self::assertSame('106000', (string) $social->amount);
        // The label changes so the user can see why the rate is lower.
        self::assertSame('accounting.declaration.line.social_acre', $social->labelKey);

        // The relief covers social contributions only.
        self::assertSame('3000', (string) $this->line($result, 'training.services_bic')->amount);
    }

    /**
     * The relief drops from 50% to 25% for micro-entreprises created on or after
     * 1 July 2026. Two companies declaring the *same* quarter therefore owe
     * different rates, because what decides is when each was created — not when
     * the quarter falls.
     *
     * Resolving on the period instead would have repriced the June company in
     * July, taking back half a relief it is entitled to for twelve months.
     */
    public function testAcreFollowsTheDateOfCreationNotTheQuarterDeclared(): void
    {
        $quarter = $this->period('2026-10-01', '2026-12-31');
        $turnover = $this->turnover([ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS]);

        $createdInJune = $this->calculate(
            $turnover,
            $this->profile(['acre' => '1'], new DateTimeImmutable('2026-06-15')),
            $quarter,
        );

        $createdInJuly = $this->calculate(
            $turnover,
            $this->profile(['acre' => '1'], new DateTimeImmutable('2026-07-15')),
            $quarter,
        );

        // 21.2 less half of it.
        self::assertSame('10.6000', (string) $this->line($createdInJune, 'social.services_bic')->rate);
        // 21.2 less a quarter of it.
        self::assertSame('15.9000', (string) $this->line($createdInJuly, 'social.services_bic')->rate);
    }

    public function testAcreStopsApplyingOnceItsWindowHasPassed(): void
    {
        $result = $this->calculate(
            $this->turnover([ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS]),
            $this->profile(['acre' => '1'], new DateTimeImmutable('2025-01-15')),
            $this->period('2026-04-01', '2026-06-30'),
        );

        $social = $this->line($result, 'social.services_bic');

        self::assertSame('21.2', (string) $social->rate);
        self::assertSame('accounting.declaration.line.social', $social->labelKey);
    }

    /**
     * With no start date there is no way to know whether the relief is still
     * running. Under-charging is the error that surfaces at the worst possible
     * moment, so the relief is simply not applied.
     */
    public function testAcreIsNotAppliedWithoutAStartDate(): void
    {
        $result = $this->calculate(
            $this->turnover([ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS]),
            $this->profile(['acre' => '1']),
        );

        self::assertSame('21.2', (string) $this->line($result, 'social.services_bic')->rate);
    }

    public function testCipavChangesTheBncRate(): void
    {
        $turnover = $this->turnover([ActivityNature::ServicesBnc->value => self::TEN_THOUSAND_EUROS]);
        $period = $this->period('2026-04-01', '2026-06-30');

        $ssi = $this->calculate($turnover, $this->profile(['pension_fund' => 'ssi']), $period);
        $cipav = $this->calculate($turnover, $this->profile(['pension_fund' => 'cipav']), $period);

        self::assertSame('26.1', (string) $this->line($ssi, 'social.services_bnc')->rate);
        self::assertSame('23.2', (string) $this->line($cipav, 'social.services_bnc')->rate);
    }

    /**
     * An unset or unrecognised pension fund falls back to SSI, which is what a
     * newly registered micro-entrepreneur gets.
     */
    public function testUnknownPensionFundFallsBackToSsi(): void
    {
        $result = $this->calculate(
            $this->turnover([ActivityNature::ServicesBnc->value => self::TEN_THOUSAND_EUROS]),
            $this->profile(['pension_fund' => 'not-a-fund']),
        );

        self::assertSame('26.1', (string) $this->line($result, 'social.services_bnc')->rate);
    }

    public function testMixedActivityIsChargedPerNatureAndWarnedAbout(): void
    {
        $result = $this->calculate(
            $this->turnover([
                ActivityNature::SaleOfGoods->value => 2_000_000,
                ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS,
            ]),
            $this->profile(),
        );

        // 20 000 EUR at 12.3%, and 10 000 EUR at 21.2% — never a blended rate.
        self::assertSame('246000', (string) $this->line($result, 'social.sale_of_goods')->amount);
        self::assertSame('212000', (string) $this->line($result, 'social.services_bic')->amount);
        self::assertSame('3000000', (string) $result->turnover);

        self::assertContains('accounting.declaration.warning.mixed_activity', $result->warnings);
    }

    public function testNoTurnoverProducesNoLines(): void
    {
        $result = $this->calculate($this->turnover([]), $this->profile());

        self::assertSame([], $result->lines);
        self::assertTrue($result->totalDue()->isZero());
    }

    /**
     * Rates come from the end of the period being declared, not from today —
     * otherwise re-opening an old declaration after a rate change would show
     * figures that were never filed.
     */
    public function testRatesAreResolvedAtTheEndOfThePeriod(): void
    {
        $turnover = $this->turnover([ActivityNature::ServicesBnc->value => self::TEN_THOUSAND_EUROS]);
        $profile = $this->profile();

        $q4_2025 = $this->calculate($turnover, $profile, $this->period('2025-10-01', '2025-12-31'));
        $q1_2026 = $this->calculate($turnover, $profile, $this->period('2026-01-01', '2026-03-31'));

        self::assertSame('24.6', (string) $this->line($q4_2025, 'social.services_bnc')->rate);
        self::assertSame('2025-01-01', $q4_2025->rateVersion);

        self::assertSame('26.1', (string) $this->line($q1_2026, 'social.services_bnc')->rate);
        self::assertSame('2026-01-01', $q1_2026->rateVersion);
    }

    /**
     * The shipped rates have been checked against their official sources, so the
     * warning stays off. It is the mechanism that matters and is kept: a
     * deployment pointing `rates_file` at its own table still gets told when
     * that table admits to being unchecked.
     */
    public function testDoesNotWarnNowThatTheShippedRatesAreVerified(): void
    {
        $result = $this->calculate(
            $this->turnover([ActivityNature::ServicesBic->value => self::TEN_THOUSAND_EUROS]),
            $this->profile(),
        );

        self::assertNotContains('accounting.declaration.warning.rates_unverified', $result->warnings);
    }

    public function testWarnsWhenEntriesWereBookedInAnotherCurrency(): void
    {
        $turnover = new TurnoverSummary(
            new DateTimeImmutable('2026-04-01'),
            new DateTimeImmutable('2026-06-30'),
            'EUR',
            [ActivityNature::ServicesBic->value => BigInteger::of(self::TEN_THOUSAND_EUROS)],
            ['USD'],
        );

        $result = $this->calculate($turnover, $this->profile());

        self::assertContains('accounting.declaration.warning.foreign_currency', $result->warnings);
    }

    private function calculate(
        TurnoverSummary $turnover,
        AccountingProfile $profile,
        ?AccountingPeriod $period = null,
    ): DeclarationResult {
        $calculator = new MicroContributionCalculator(
            new FrenchRateTable(dirname(__DIR__, 3) . '/Resources/config/fr_micro_rates.php'),
        );

        return $calculator->calculate(
            $turnover,
            $profile,
            $period ?? $this->period('2026-04-01', '2026-06-30'),
        );
    }

    /**
     * @param array<string, int> $byNature minor units, keyed by activity value
     */
    private function turnover(array $byNature): TurnoverSummary
    {
        $amounts = [];

        foreach ($byNature as $nature => $amount) {
            $amounts[$nature] = BigInteger::of($amount);
        }

        return new TurnoverSummary(
            new DateTimeImmutable('2026-04-01'),
            new DateTimeImmutable('2026-06-30'),
            'EUR',
            $amounts,
        );
    }

    /**
     * @param array<string, string> $options
     */
    private function profile(array $options = [], ?DateTimeImmutable $startDate = null): AccountingProfile
    {
        return new AccountingProfile(
            regimeCode: 'fr_micro',
            vatExempt: true,
            vatExemptMention: 'TVA non applicable, article 293 B du CGI',
            activityStartDate: $startDate,
            primaryActivity: ActivityNature::ServicesBic,
            declarationPeriodicity: PeriodType::Quarter,
            vatPeriodicity: null,
            fiscalYearStartMonth: 1,
            currencyCode: 'EUR',
            regimeOptions: $options,
        );
    }

    private function period(string $start, string $end): AccountingPeriod
    {
        return new AccountingPeriod()
            ->setType(PeriodType::Quarter)
            ->setYear((int) new DateTimeImmutable($start)->format('Y'))
            ->setOrdinal(PeriodType::Quarter->ordinalOf(new DateTimeImmutable($start)))
            ->setStartDate(new DateTimeImmutable($start))
            ->setEndDate(new DateTimeImmutable($end));
    }

    private function line(DeclarationResult $result, string $key): DeclarationLine
    {
        $matches = array_values(array_filter(
            $result->lines,
            static fn (DeclarationLine $line): bool => $line->key === $key,
        ));

        self::assertCount(1, $matches, sprintf('Expected exactly one "%s" line.', $key));

        return $matches[0];
    }
}
