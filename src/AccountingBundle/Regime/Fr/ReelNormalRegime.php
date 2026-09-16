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
use Augias\AccountingBundle\Model\ThresholdSet;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\RegimeInterface;
use DateTimeImmutable;
use Override;

/**
 * The French régime réel normal — VAT charged on sales, reclaimed on purchases,
 * and declared on the CA3.
 *
 * This is where a company lands once it leaves the franchise en base, and from
 * 1 January 2027 it is the only place left to land: article 38 of the loi de
 * finances pour 2025 (loi n° 2025-127 du 14 février 2025) abolishes the régime
 * simplifié d'imposition on that date, taking the annual CA12 and its two
 * instalments with it. The simplified regime is deliberately not modelled here:
 * it would have been born with three months to live.
 *
 * What this regime contributes is mostly what it *removes*. There is no
 * turnover ceiling to watch — a company here is already past every limit the
 * micro regime measures against — and nothing it owes is computed from these
 * books, because contributions under the réel are assessed on profit, which
 * cash-basis receipts and payments do not yield. So it keeps both statutory
 * books, declares VAT, and says so plainly rather than producing a figure it
 * cannot stand behind.
 *
 * @see \Augias\AccountingBundle\Tests\Regime\Fr\ReelNormalRegimeTest
 */
final readonly class ReelNormalRegime implements RegimeInterface
{
    public const string CODE = 'fr_reel_normal';

    public function code(): string
    {
        return self::CODE;
    }

    public function labelKey(): string
    {
        return 'accounting.regime.fr_reel_normal.label';
    }

    public function descriptionKey(): string
    {
        return 'accounting.regime.fr_reel_normal.description';
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
     * Both books, always. Unlike the micro regime, where the purchase register
     * is only required of resale activities, VAT here is reclaimed on every
     * kind of purchase — so a services business that keeps no purchase register
     * has nowhere to record the tax it is entitled to deduct.
     *
     * @return list<LedgerBook>
     */
    public function books(AccountingProfile $profile): array
    {
        return [LedgerBook::Revenue, LedgerBook::Purchase];
    }

    /**
     * No split carries any fiscal consequence here: the natures exist to
     * separate turnover the micro regime charges at different rates, and this
     * regime charges nothing on turnover at all.
     *
     * They are still all offered rather than collapsed into one, because the
     * revenue book legitimately records what was sold, and a company that grew
     * out of the micro regime arrives with a history split this way. Forcing it
     * into a single nature would destroy that, and gain nothing.
     *
     * @return list<ActivityNature>
     */
    public function activityNatures(): array
    {
        return ActivityNature::cases();
    }

    /**
     * Monthly is the rule under the réel normal (CGI, art. 287-1). Quarterly is
     * a tolerance, open when the tax payable over the year runs under 4 000 €
     * — assessed at the start of each quarter on the four civil quarters
     * behind it, not on a calendar year, which is why it is the user's to
     * choose here rather than something this class could decide for them.
     *
     * @return list<PeriodType>
     */
    public function declarationPeriodicities(): array
    {
        return [PeriodType::Month, PeriodType::Quarter];
    }

    /**
     * The point of the regime. A company that chose it is in scope for VAT;
     * one that is not in scope belongs in the franchise en base, under the
     * micro regime.
     */
    public function isVatExemptByDefault(): bool
    {
        return false;
    }

    public function filingUrl(): string
    {
        return 'https://www.impots.gouv.fr';
    }

    /**
     * VAT and nothing else. Contributions under the réel are worked out from
     * the profit of a financial year, by a computation this module does not
     * perform and these books do not contain.
     *
     * @return list<DeclarationKind>
     */
    public function declarationKinds(): array
    {
        return [];
    }

    /**
     * No limits at all — every ceiling the micro regime measures against is
     * behind a company that files a CA3.
     */
    #[Override]
    public function thresholds(AccountingProfile $profile, DateTimeImmutable $on): ThresholdSet
    {
        return new ThresholdSet();
    }

    /**
     * Turnover, and no charges derived from it.
     *
     * {@see declarationKinds()} means this is never reached through
     * {@see \Augias\AccountingBundle\Service\DeclarationBuilder::kindsOwed()};
     * it answers honestly for anything that calls it directly, rather than
     * inventing a contribution the réel does not assess this way.
     */
    #[Override]
    public function calculate(
        TurnoverSummary $turnover,
        AccountingProfile $profile,
        AccountingPeriod $period,
    ): DeclarationResult {
        return new DeclarationResult(
            $turnover->total(),
            [],
            $profile->currencyCode,
            warnings: ['accounting.regime.fr_reel_normal.contributions_not_computed'],
        );
    }
}
