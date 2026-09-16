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
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\DeclarationKind;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\DeclarationResult;
use Augias\AccountingBundle\Model\Threshold;
use Augias\AccountingBundle\Model\ThresholdSet;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\RegimeInterface;
use DateTimeImmutable;
use Override;
use function sprintf;

/**
 * The French micro-entreprise regime.
 *
 * Bookkeeping under it is deliberately minimal — a chronological record of
 * receipts, a register of purchases for resale activities, and nothing else.
 * No balance sheet, no depreciation, no accruals: everything is cash-basis, and
 * charges are a percentage of gross takings with no deductions.
 *
 * What this class contributes is the *rules*: which books are required, which
 * turnover limits apply, and — by delegating to
 * {@see MicroContributionCalculator} — what is owed. The figures themselves all
 * come from the dated table in {@see FrenchRateTable}, never from here.
 *
 * @see \Augias\AccountingBundle\Tests\Regime\Fr\MicroEntrepriseRegimeTest
 */
final readonly class MicroEntrepriseRegime implements RegimeInterface
{
    public const string CODE = 'fr_micro';

    public function __construct(
        private FrenchRateTable $rates,
        private MicroContributionCalculator $calculator,
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function labelKey(): string
    {
        return 'accounting.regime.fr_micro.label';
    }

    public function descriptionKey(): string
    {
        return 'accounting.regime.fr_micro.description';
    }

    public function countryCode(): string
    {
        return 'FR';
    }

    public function issuedDocumentsAreFinal(): bool
    {
        return true;
    }

    /**
     * The revenue book is compulsory for everyone. The purchase register is
     * only required of resale and accommodation activities — a pure services
     * business is not obliged to keep one, and showing it an empty statutory
     * register it never has to fill in would be misleading.
     *
     * @return list<LedgerBook>
     */
    public function books(AccountingProfile $profile): array
    {
        $books = [LedgerBook::Revenue];

        if ($profile->primaryActivity->isSaleOfGoods()) {
            $books[] = LedgerBook::Purchase;
        }

        return $books;
    }

    /**
     * @return list<ActivityNature>
     */
    public function activityNatures(): array
    {
        return ActivityNature::cases();
    }

    /**
     * @return list<PeriodType>
     */
    public function declarationPeriodicities(): array
    {
        return [PeriodType::Month, PeriodType::Quarter];
    }

    /**
     * Turnover to URSSAF. VAT is added on top when the company is in scope,
     * which is the ordinary situation of a micro-entrepreneur past the
     * franchise threshold.
     *
     * @return list<DeclarationKind>
     */
    public function declarationKinds(): array
    {
        return [DeclarationKind::SocialContributions];
    }

    /**
     * New micro-entreprises start in franchise en base. Only a default — the
     * setting stays the user's to change, since crossing the tolerance
     * threshold makes them liable part-way through a year.
     */
    public function isVatExemptByDefault(): bool
    {
        return true;
    }

    public function filingUrl(): string
    {
        return 'https://www.autoentrepreneur.urssaf.fr';
    }

    #[Override]
    public function thresholds(AccountingProfile $profile, DateTimeImmutable $on): ThresholdSet
    {
        $year = (int) $on->format('Y');
        $currency = $profile->currencyCode;
        $thresholds = [];

        foreach (ActivityNature::cases() as $nature) {
            $ceiling = new Threshold(
                sprintf('micro_ceiling.%s', $nature->value),
                'accounting.threshold.micro_ceiling',
                $this->rates->microCeiling($nature, $on),
                $currency,
                $nature,
            );

            // Only the regime ceiling is scaled down for a partial first year;
            // the VAT thresholds are not prorated.
            $thresholds[] = $this->prorate($ceiling, $profile, $year);

            $thresholds[] = new Threshold(
                sprintf('vat_franchise.base.%s', $nature->value),
                'accounting.threshold.vat_franchise_base',
                $this->rates->vatFranchiseBase($nature, $on),
                $currency,
                $nature,
            );

            $thresholds[] = new Threshold(
                sprintf('vat_franchise.tolerance.%s', $nature->value),
                'accounting.threshold.vat_franchise_tolerance',
                $this->rates->vatFranchiseTolerance($nature, $on),
                $currency,
                $nature,
            );
        }

        return new ThresholdSet($thresholds);
    }

    #[Override]
    public function calculate(
        TurnoverSummary $turnover,
        AccountingProfile $profile,
        AccountingPeriod $period,
    ): DeclarationResult {
        return $this->calculator->calculate($turnover, $profile, $period);
    }

    /**
     * Scale a limit down to the days actually traded, when the company started
     * part-way through the year in question.
     */
    private function prorate(Threshold $threshold, AccountingProfile $profile, int $year): Threshold
    {
        if (! $profile->isFirstYearOfActivity($year)) {
            return $threshold;
        }

        $start = $profile->activityStartDate;

        if (! $start instanceof DateTimeImmutable) {
            return $threshold;
        }

        $endOfYear = $start->setDate($year, 12, 31);
        $daysTraded = (int) $start->diff($endOfYear)->days + 1;
        $daysInYear = (int) $endOfYear->format('z') + 1;

        return $threshold->proratedTo($daysTraded, $daysInYear);
    }
}
