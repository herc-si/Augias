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

namespace Augias\AccountingBundle\Dashboard;

use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\AccountingBundle\Service\LimitUsageCalculator;
use Augias\AccountingBundle\Service\TurnoverCalculator;
use Augias\CoreBundle\Entity\Company;
use Augias\DashboardBundle\Attribute\AsDashboardWidget;
use Augias\DashboardBundle\Enum\WidgetWidth;
use Augias\DashboardBundle\Enum\WidgetZone;
use Augias\DashboardBundle\Widgets\WidgetInterface;
use DateTimeImmutable;
use RuntimeException;

/**
 * Where this year's turnover stands against the limits the company's regime
 * sets — the one accounting question worth answering without being asked.
 *
 * Passing a VAT threshold makes a micro-entrepreneur liable for VAT part-way
 * through a year, and passing the regime ceiling two years running ends the
 * regime. {@see \Augias\AccountingBundle\Service\ThresholdMonitor} already
 * emails when a milestone is crossed, but an email is a one-off: this is the
 * standing answer, on the page the user opens anyway, while there is still
 * room to act on it.
 *
 * The widget lives in this bundle rather than in DashboardBundle because
 * everything it knows — regimes, ceilings, prorata — is accounting knowledge,
 * and `#[AsDashboardWidget]` is published precisely so a feature bundle can
 * contribute a card without the dashboard learning about it. SaasBundle already
 * contributes a checklist item the same way.
 *
 * @see \Augias\AccountingBundle\Tests\Dashboard\TurnoverAgainstLimitsWidgetTest
 */
#[AsDashboardWidget(
    id: 'accounting_turnover',
    label: 'dashboard.widget.accounting_turnover',
    icon: 'tabler:scale',
    zone: WidgetZone::LeftColumn,
    // Above "Attention Required": an overdue invoice is this week's problem, a
    // ceiling being approached is this year's, and the second one is the one
    // nobody goes looking for.
    priority: 130,
    width: WidgetWidth::Full,
)]
final readonly class TurnoverAgainstLimitsWidget implements WidgetInterface
{
    /**
     * Enough to show the ceiling and the VAT threshold that matter, not every
     * limit a regime defines. A card that leads with a limit at 3% has buried
     * the one at 94%.
     */
    private const int LIMITS_SHOWN = 3;

    public function __construct(
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $registry,
        private CurrentCompany $currentCompany,
        private TurnoverCalculator $turnoverCalculator,
        private LimitUsageCalculator $limitUsageCalculator,
    ) {
    }

    /**
     * Nothing to say until a regime has been chosen — or when the regime chosen
     * has no limits to be measured against.
     *
     * An unconfigured company has no business being told its turnover is within
     * a limit it never picked, and this runs before getData(), so a company that
     * keeps no books pays for none of the queries below. The picker does not
     * offer the card either — which is the honest answer, since putting it back
     * would not make it render.
     *
     * The same goes for a company on the réel normal: it is past every ceiling
     * the micro regime watches, so a card headed "turnover against limits" would
     * have no limits to show. Resolving the thresholds here costs the rate-table
     * read that getData() would do anyway, and only for a configured company.
     */
    public function supports(): bool
    {
        $profile = $this->profileProvider->forCompany();

        if (! $profile->isConfigured() || ! $this->company() instanceof Company) {
            return false;
        }

        $regime = $this->registry->forProfile($profile);

        return $regime instanceof RegimeInterface
            && count($regime->thresholds($profile, new DateTimeImmutable('today'))) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $profile = $this->profileProvider->forCompany();
        $regime = $this->registry->forProfile($profile);
        $company = $this->company();

        // supports() has already established both, but it is checked per request
        // and a company can be switched between that call and this one. Returning
        // an empty card beats a type error on the dashboard.
        if (! $regime instanceof RegimeInterface || ! $company instanceof Company) {
            return $this->nothingToShow($profile);
        }

        $today = new DateTimeImmutable('today');
        $turnover = $this->turnoverCalculator->yearToDate($company, $profile->currencyCode, $today);
        $limits = $this->limitUsageCalculator->forTurnover($regime, $profile, $turnover, $today);

        return [
            'profile' => $profile,
            'regime' => $regime,
            'turnover' => $turnover,
            'limits' => $this->limitUsageCalculator->mostPressing($limits, self::LIMITS_SHOWN),
            'hiddenLimits' => max(0, count($limits) - self::LIMITS_SHOWN),
            'year' => (int) $today->format('Y'),
        ];
    }

    public function getTemplate(): string
    {
        return '@AugiasAccounting/Widget/turnover_against_limits.html.twig';
    }

    /**
     * @return array<string, mixed>
     */
    private function nothingToShow(AccountingProfile $profile): array
    {
        return [
            'profile' => $profile,
            'regime' => null,
            'turnover' => null,
            'limits' => [],
            'hiddenLimits' => 0,
            'year' => (int) new DateTimeImmutable('today')->format('Y'),
        ];
    }

    /**
     * The active tenant, or null when there is none.
     *
     * CurrentCompany::get() reads the selector and then the database, and a
     * failure there is an outage rather than an answer — but this is called from
     * supports(), where an exception would take the whole dashboard down for a
     * card the user may not even have.
     */
    private function company(): ?Company
    {
        try {
            return $this->currentCompany->get();
        } catch (RuntimeException) {
            return null;
        }
    }
}
