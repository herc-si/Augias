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

namespace Augias\AccountingBundle\Action;

use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\ThresholdAlert;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\LimitUsage;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Repository\AccountingPeriodRepository;
use Augias\AccountingBundle\Repository\ThresholdAlertRepository;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CompanyBooks;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\AccountingBundle\Service\LedgerLockDate;
use Augias\AccountingBundle\Service\LimitUsageCalculator;
use Augias\AccountingBundle\Service\TurnoverCalculator;
use Augias\CoreBundle\Entity\Company;
use DateTimeImmutable;
use Symfony\Bridge\Twig\Attribute\Template;

/**
 * The accounting home page: where the company stands this year, and what it
 * still has to do about it.
 *
 * Until a regime is chosen there is nothing meaningful to show — an empty book
 * measured against limits the user never picked would be worse than no page at
 * all — so this hands the template the profile and lets it render either the
 * setup prompt or the books.
 *
 * The turnover shown is year-to-date rather than the current period's, because
 * that is what every limit on the page is expressed against.
 */
final readonly class Index
{
    public function __construct(
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $registry,
        private CurrentCompany $currentCompany,
        private TurnoverCalculator $turnoverCalculator,
        private LimitUsageCalculator $limitUsageCalculator,
        private AccountingPeriodRepository $periodRepository,
        private ThresholdAlertRepository $alertRepository,
        private LedgerLockDate $lockDate,
        private CompanyBooks $books,
    ) {
    }

    /**
     * @return array{
     *     profile: AccountingProfile,
     *     regime: RegimeInterface|null,
     *     books: list<LedgerBook>,
     *     turnover: TurnoverSummary|null,
     *     limits: list<LimitUsage>,
     *     period: AccountingPeriod|null,
     *     periodHasEnded: bool,
     *     alerts: list<ThresholdAlert>,
     *     year: int,
     *     lockDate: DateTimeImmutable|null
     * }
     */
    #[Template('@AugiasAccounting/Default/index.html.twig')]
    public function __invoke(): array
    {
        $profile = $this->profileProvider->forCompany();
        $regime = $this->registry->forProfile($profile);
        $company = $this->currentCompany->get();
        $today = new DateTimeImmutable('today');
        $year = (int) $today->format('Y');

        if (! $regime instanceof RegimeInterface || ! $company instanceof Company) {
            return [
                'profile' => $profile,
                'regime' => $regime,
                'books' => [],
                'turnover' => null,
                'limits' => [],
                'period' => null,
                'periodHasEnded' => false,
                'alerts' => [],
                'year' => $year,
                'lockDate' => null,
            ];
        }

        $turnover = $this->turnoverCalculator->yearToDate($company, $profile->currencyCode, $today);

        return [
            'profile' => $profile,
            'regime' => $regime,
            'books' => $this->books->statutory($profile),
            'turnover' => $turnover,
            'limits' => $this->limitUsageCalculator->forTurnover($regime, $profile, $turnover, $today),
            'period' => $period = $this->periodRepository->findForDate($company, $profile->declarationPeriodicity, $today, $profile->fiscalYearStartMonth),
            // Whether the page may offer to seal it. A period still running
            // cannot be closed — see AccountingPeriodManager::close() — so
            // offering the button would only produce a refusal.
            'periodHasEnded' => $period instanceof AccountingPeriod && $period->getEndDate() < $today,
            'alerts' => $this->alertRepository->findForYear($company, $year),
            'year' => $year,
            // What the books are shut up to: the date the user set, or the end
            // of the last period sealed, whichever is later.
            'lockDate' => $this->lockDate->forCompany($company),
        ];
    }
}
