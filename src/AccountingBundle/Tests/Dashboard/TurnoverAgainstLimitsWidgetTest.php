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

namespace Augias\AccountingBundle\Tests\Dashboard;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Dashboard\TurnoverAgainstLimitsWidget;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\LimitUsage;
use Augias\AccountingBundle\Model\TurnoverSummary;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TurnoverAgainstLimitsWidget::class)]
final class TurnoverAgainstLimitsWidgetTest extends AccountingWidgetTestCase
{
    /**
     * A company that has not chosen a regime has no business being told its
     * turnover is within a limit it never picked. supports() runs before
     * getData(), so this is also what keeps the widget from querying the ledger
     * for every company that keeps no books.
     */
    public function testDoesNotApplyUntilARegimeIsChosen(): void
    {
        $this->config->set(AccountingSettings::REGIME, '');

        self::assertFalse($this->widget()->supports());
    }

    /**
     * A regime recorded in the settings that no longer exists — a deployment
     * dropped it — reads the same as none at all rather than taking the
     * dashboard down.
     */
    public function testDoesNotApplyForARegimeThatNoLongerExists(): void
    {
        $this->config->set(AccountingSettings::REGIME, 'a_regime_from_a_previous_release');

        self::assertFalse($this->widget()->supports());
    }

    public function testAppliesOnceARegimeIsChosen(): void
    {
        $this->configureMicroEntreprise();

        self::assertTrue($this->widget()->supports());
    }

    /**
     * A company on the réel normal is past every ceiling the micro regime
     * watches, so a card headed "turnover against limits" would have no limits
     * to put in it. Same answer as an unconfigured company: the card does not
     * apply, and the picker stops offering it.
     */
    public function testDoesNotApplyToARegimeWithNoLimitsToMeasureAgainst(): void
    {
        $this->config->set(AccountingSettings::REGIME, ReelNormalRegime::CODE);
        $this->config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBic->value);
        $this->config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Month->value);

        self::assertFalse($this->widget()->supports());
    }

    public function testReportsAnEmptyRevenueBookRatherThanCeilingsAgainstZero(): void
    {
        $this->configureMicroEntreprise();

        $data = $this->widget()->getData();

        self::assertInstanceOf(TurnoverSummary::class, $data['turnover']);
        self::assertTrue($data['turnover']->isEmpty());
        self::assertSame([], $data['limits']);
        self::assertSame(0, $data['hiddenLimits']);
    }

    public function testMeasuresThisYearsTurnoverAgainstTheRegimesLimits(): void
    {
        $this->configureMicroEntreprise();
        $this->revenue(1_000_000, new DateTimeImmutable('today'));

        $data = $this->widget()->getData();

        self::assertInstanceOf(TurnoverSummary::class, $data['turnover']);
        self::assertSame('1000000', (string) $data['turnover']->total());
        self::assertNotSame([], $data['limits']);
        self::assertContainsOnlyInstancesOf(LimitUsage::class, $data['limits']);
        self::assertSame((int) new DateTimeImmutable('today')->format('Y'), $data['year']);
    }

    /**
     * Turnover recorded last year is not measured against this year's ceilings:
     * the limits are annual and cumulative, and January resets them.
     */
    public function testLastYearsTurnoverIsNotCountedAgainstThisYear(): void
    {
        $this->configureMicroEntreprise();
        $this->revenue(1_000_000, new DateTimeImmutable('today')->modify('-1 year'));

        $data = $this->widget()->getData();

        self::assertInstanceOf(TurnoverSummary::class, $data['turnover']);
        self::assertTrue($data['turnover']->isEmpty());
    }

    /**
     * The card renders, and renders the empty book as a state of its own. A
     * configured regime with nothing in the revenue book is normal — the company
     * has chosen how it is taxed and has not been paid yet this year — so it
     * says so instead of drawing ceilings against zero.
     */
    public function testRendersAnEmptyRevenueBook(): void
    {
        $this->configureMicroEntreprise();

        $this->assertMatchesHtmlSnapshot($this->render($this->widget()));
    }

    public function testRendersTurnoverAgainstItsLimits(): void
    {
        $this->configureMicroEntreprise();
        $this->revenue(6_500_000, new DateTimeImmutable('today'));

        // 6,500,000 of a 7,770,000 BNC ceiling: past the 80% mark, so the bar is
        // the amber one and the snapshot is what holds that.
        $this->assertMatchesHtmlSnapshot($this->render($this->widget()));
    }

    public function testGetTemplate(): void
    {
        self::assertSame(
            '@AugiasAccounting/Widget/turnover_against_limits.html.twig',
            $this->widget()->getTemplate(),
        );
    }

    private function revenue(int $amount, DateTimeImmutable $on): void
    {
        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setEntryDate($on)
            ->setLabel('Invoice payment')
            ->setCounterpartyName('Johnston PLC')
            ->setAmount(BigInteger::of($amount))
            ->setCurrencyCode('EUR')
            ->setActivityNature(ActivityNature::ServicesBnc);

        $entry->setCompany($this->companyReference());

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    private function widget(): TurnoverAgainstLimitsWidget
    {
        $widget = self::getContainer()->get(TurnoverAgainstLimitsWidget::class);
        self::assertInstanceOf(TurnoverAgainstLimitsWidget::class, $widget);

        return $widget;
    }
}
