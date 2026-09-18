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

namespace Augias\AccountingBundle\Regime\Fr;

use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Enum\PensionFund;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\DeclarationLine;
use Augias\AccountingBundle\Model\DeclarationResult;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\ContributionCalculatorInterface;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use function count;
use function sprintf;

/**
 * Works out what a French micro-entrepreneur owes on a period's takings.
 *
 * The arithmetic itself is simple — turnover times a rate, per activity — and
 * that is the point: the micro regime charges on gross receipts, with no
 * deductions to model. What the work really consists of is picking the right
 * rate, which depends on the activity, the date, which body collects the
 * pension contributions, and whether the ACRE relief is still running.
 *
 * Every charge comes back as its own {@see DeclarationLine} carrying the base
 * and the rate it came from. The user has to transcribe these into URSSAF's own
 * form box by box, and — since the rates here are not authoritative — has to be
 * able to see and override whichever one is wrong.
 *
 * @see \Augias\AccountingBundle\Tests\Regime\Fr\MicroContributionCalculatorTest
 */
final readonly class MicroContributionCalculator implements ContributionCalculatorInterface
{
    public function __construct(
        private FrenchRateTable $rates,
    ) {
    }

    public function calculate(
        TurnoverSummary $turnover,
        AccountingProfile $profile,
        AccountingPeriod $period,
    ): DeclarationResult {
        // Rates are resolved at the end of the period, which is the date the
        // declaration relates to — not "now", which would silently reprice an
        // old period after a rate change.
        $on = $period->getEndDate();
        $pensionFund = $this->pensionFund($profile);
        $acreApplies = $this->acreApplies($profile, $period);
        $incomeTaxOption = $profile->boolOption('income_tax_option');

        $lines = [];

        foreach ($turnover->activeNatures() as $nature) {
            $base = $turnover->forNature($nature);

            $socialRate = $this->rates->socialRate($nature, $on, $pensionFund);

            if ($acreApplies) {
                $socialRate = $this->applyAcre($socialRate, $profile->activityStartDate ?? $on);
            }

            $lines[] = DeclarationLine::fromRate(
                sprintf('social.%s', $nature->value),
                $acreApplies
                    ? 'accounting.declaration.line.social_acre'
                    : 'accounting.declaration.line.social',
                DeclarationLine::KIND_CONTRIBUTION,
                $base,
                $socialRate,
                $turnover->currencyCode,
                $nature,
            );

            $trainingRate = $this->rates->trainingRate($nature, $on);

            if (! $trainingRate->isZero()) {
                $lines[] = DeclarationLine::fromRate(
                    sprintf('training.%s', $nature->value),
                    'accounting.declaration.line.training',
                    DeclarationLine::KIND_LEVY,
                    $base,
                    $trainingRate,
                    $turnover->currencyCode,
                    $nature,
                );
            }

            if ($incomeTaxOption) {
                $lines[] = DeclarationLine::fromRate(
                    sprintf('income_tax.%s', $nature->value),
                    'accounting.declaration.line.income_tax',
                    DeclarationLine::KIND_INCOME_TAX,
                    $base,
                    $this->rates->incomeTaxRate($nature, $on),
                    $turnover->currencyCode,
                    $nature,
                );
            }
        }

        return new DeclarationResult(
            turnover: $turnover->total(),
            lines: $lines,
            currencyCode: $turnover->currencyCode,
            rateVersion: $this->rates->contributionVersion($on),
            warnings: $this->warnings($turnover, $on),
        );
    }

    /**
     * ACRE takes a percentage off the social rate — not off the base, and not
     * off the training levy or the income-tax payment, which it never covers.
     *
     * Resolved on the date the activity started, not the period being declared.
     * The terms of the relief are fixed when the company is created: the
     * reduction falls from 50% to 25% for micro-entreprises created on or after
     * 1 July 2026, and one created in June keeps its 50% for the twelve months
     * that follow. Resolving on the period would quietly reprice it in July.
     */
    private function applyAcre(BigDecimal $socialRate, DateTimeImmutable $createdOn): BigDecimal
    {
        $reduction = $this->rates->acreReductionPercent($createdOn);

        if ($reduction->isZero()) {
            return $socialRate;
        }

        return $socialRate
            ->multipliedBy(BigDecimal::of('100')->minus($reduction))
            ->dividedBy(100, 4, RoundingMode::HalfUp);
    }

    /**
     * The relief runs for a fixed number of months from the start of activity.
     * With no start date recorded there is no way to tell whether it still
     * applies, so it is not applied — quietly under-charging is the failure
     * that gets noticed at the worst moment.
     */
    private function acreApplies(
        AccountingProfile $profile,
        AccountingPeriod $period,
    ): bool {
        if (! $profile->boolOption('acre')) {
            return false;
        }

        $start = $profile->activityStartDate;

        if (! $start instanceof DateTimeImmutable) {
            return false;
        }

        // Same reasoning as applyAcre(): how long the relief runs is part of the
        // terms fixed at creation, not of the period being declared.
        $months = $this->rates->acreDurationMonths($start);

        if ($months <= 0) {
            return false;
        }

        return $period->getStartDate() < $start->modify(sprintf('+%d months', $months));
    }

    private function pensionFund(AccountingProfile $profile): PensionFund
    {
        $value = $profile->option('pension_fund');

        return (null === $value ? null : PensionFund::tryFrom($value)) ?? PensionFund::Ssi;
    }

    /**
     * @return list<string>
     */
    private function warnings(TurnoverSummary $turnover, DateTimeImmutable $on): array
    {
        $warnings = [];

        if (! $this->rates->isVerified($on)) {
            $warnings[] = 'accounting.declaration.warning.rates_unverified';
        }

        if ($turnover->hasForeignCurrencies()) {
            $warnings[] = 'accounting.declaration.warning.foreign_currency';
        }

        // A mixed activity is capped by a combined ceiling as well as a
        // per-activity one, and the interaction is not modelled here.
        if (count($turnover->activeNatures()) > 1) {
            $warnings[] = 'accounting.declaration.warning.mixed_activity';
        }

        return $warnings;
    }
}
