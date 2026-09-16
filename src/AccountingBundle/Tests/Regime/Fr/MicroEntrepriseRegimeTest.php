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

use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Regime\Fr\FrenchRateTable;
use Augias\AccountingBundle\Regime\Fr\MicroContributionCalculator;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function dirname;

#[CoversClass(MicroEntrepriseRegime::class)]
final class MicroEntrepriseRegimeTest extends TestCase
{
    /** The BNC and BIC services ceiling for 2026-2028, in cents. */
    private const int SERVICES_CEILING = 8_360_000;

    /**
     * The purchase register is only required of resale and accommodation
     * activities. Showing a services business a statutory register it never has
     * to produce would be inventing an obligation.
     */
    public function testAServicesBusinessKeepsOnlyTheRevenueBook(): void
    {
        self::assertSame([LedgerBook::Revenue], $this->regime()->books($this->profile()));
    }

    public function testAResaleBusinessAlsoKeepsThePurchaseRegister(): void
    {
        self::assertSame(
            [LedgerBook::Revenue, LedgerBook::Purchase],
            $this->regime()->books($this->profile(ActivityNature::SaleOfGoods)),
        );
    }

    public function testTurnoverIsDeclaredMonthlyOrQuarterlyAndNotYearly(): void
    {
        self::assertSame(
            [PeriodType::Month, PeriodType::Quarter],
            $this->regime()->declarationPeriodicities(),
        );
    }

    public function testAFullYearOfTradingIsMeasuredAgainstTheWholeCeiling(): void
    {
        $ceiling = $this->regime()
            ->thresholds($this->profile(start: '2020-03-01'), new DateTimeImmutable('2026-12-31'))
            ->get('micro_ceiling.services_bnc');

        self::assertNotNull($ceiling);
        self::assertFalse($ceiling->prorated);
        self::assertSame((string) self::SERVICES_CEILING, (string) $ceiling->amount);
    }

    /**
     * A company that started part-way through the year is measured against the
     * part of the year it actually traded — the prorata temporis rule.
     *
     * From 1 July 2026 there are 184 days left of 365, so the ceiling comes to
     * 184/365 of the full figure.
     */
    public function testAPartialFirstYearScalesTheCeilingDown(): void
    {
        $ceiling = $this->regime()
            ->thresholds($this->profile(start: '2026-07-01'), new DateTimeImmutable('2026-12-31'))
            ->get('micro_ceiling.services_bnc');

        self::assertNotNull($ceiling);
        self::assertTrue($ceiling->prorated);
        self::assertSame('4214356', (string) $ceiling->amount);
    }

    /**
     * Only the regime ceiling is prorated. The VAT thresholds are not, and a
     * first-year company crossing one is liable just the same.
     */
    public function testTheVatThresholdsAreNeverProrated(): void
    {
        $thresholds = $this->regime()->thresholds($this->profile(start: '2026-07-01'), new DateTimeImmutable('2026-12-31'));

        $base = $thresholds->get('vat_franchise.base.services_bnc');

        self::assertNotNull($base);
        self::assertFalse($base->prorated);
    }

    private function regime(): MicroEntrepriseRegime
    {
        $rates = new FrenchRateTable(dirname(__DIR__, 3) . '/Resources/config/fr_micro_rates.php');

        return new MicroEntrepriseRegime($rates, new MicroContributionCalculator($rates));
    }

    private function profile(
        ActivityNature $activity = ActivityNature::ServicesBnc,
        ?string $start = null,
    ): AccountingProfile {
        return new AccountingProfile(
            regimeCode: MicroEntrepriseRegime::CODE,
            vatExempt: true,
            vatExemptMention: '',
            activityStartDate: null === $start ? null : new DateTimeImmutable($start),
            primaryActivity: $activity,
            declarationPeriodicity: PeriodType::Quarter,
            vatPeriodicity: null,
            fiscalYearStartMonth: 1,
            currencyCode: 'EUR',
        );
    }
}
