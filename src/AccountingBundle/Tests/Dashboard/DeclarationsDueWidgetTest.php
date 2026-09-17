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
use Augias\AccountingBundle\Dashboard\DeclarationsDueWidget;
use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\Declaration;
use Augias\AccountingBundle\Enum\DeclarationAction;
use Augias\AccountingBundle\Enum\DeclarationStatus;
use Augias\AccountingBundle\Enum\PeriodStatus;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\PendingDeclaration;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DeclarationsDueWidget::class)]
final class DeclarationsDueWidgetTest extends AccountingWidgetTestCase
{
    public function testDoesNotApplyUntilARegimeIsChosen(): void
    {
        $this->config->set(AccountingSettings::REGIME, '');

        self::assertFalse($this->widget()->supports());
    }

    public function testAppliesOnceARegimeIsChosen(): void
    {
        $this->configureMicroEntreprise();

        self::assertTrue($this->widget()->supports());
    }

    public function testAPeriodStillRunningIsNotOutstanding(): void
    {
        $this->configureMicroEntreprise();
        $this->period(PeriodStatus::Open, new DateTimeImmutable('today')->modify('+1 month'));

        self::assertSame([], $this->widget()->getData()['pending']);
    }

    /**
     * A quarter is not over on its last day: entries can still be booked into
     * it until midnight, and nothing can be declared before that.
     *
     * `MissingPeriod::hasEnded()` has always taken that line for a quarter with
     * no row. The query behind this one disagreed on SQLite, because it bound
     * its date as a datetime — so the same quarter counted as ended if it had a
     * row and as still running if it did not.
     */
    public function testAPeriodEndingTodayIsNotYetOutstanding(): void
    {
        $this->configureMicroEntreprise();
        $this->period(PeriodStatus::Closed, new DateTimeImmutable('today'));

        self::assertSame([], $this->widget()->getData()['pending']);
    }

    /**
     * An ended period whose books are still open is reported as needing closing,
     * not as needing filing: they are different jobs on different screens.
     */
    public function testAnEndedOpenPeriodNeedsClosing(): void
    {
        $this->configureMicroEntreprise();
        $this->period(PeriodStatus::Open, new DateTimeImmutable('today')->modify('-40 days'));

        $pending = $this->widget()->getData()['pending'];

        self::assertCount(1, $pending);
        self::assertInstanceOf(PendingDeclaration::class, $pending[0]);
        self::assertSame(DeclarationAction::Close, $pending[0]->action);
        self::assertSame(40, $pending[0]->daysSincePeriodEnded());
    }

    public function testAClosedPeriodWithNoDeclarationRowNeedsFiling(): void
    {
        $this->configureMicroEntreprise();
        $this->period(PeriodStatus::Closed, new DateTimeImmutable('today')->modify('-40 days'));

        $pending = $this->widget()->getData()['pending'];

        self::assertCount(1, $pending);
        self::assertInstanceOf(PendingDeclaration::class, $pending[0]);
        self::assertSame(DeclarationAction::File, $pending[0]->action);
        self::assertNull($pending[0]->declaration);
    }

    public function testADraftDeclarationIsStillOutstanding(): void
    {
        $this->configureMicroEntreprise();
        $period = $this->period(PeriodStatus::Closed, new DateTimeImmutable('today')->modify('-40 days'));
        $this->declaration($period, DeclarationStatus::Draft);

        $pending = $this->widget()->getData()['pending'];

        self::assertCount(1, $pending);
        self::assertInstanceOf(PendingDeclaration::class, $pending[0]);
        self::assertInstanceOf(Declaration::class, $pending[0]->declaration);
    }

    /**
     * Filed is done. Augias cannot observe a filing, so this is the user's own
     * statement that they did it — and once made, the period stops nagging.
     */
    public function testAFiledPeriodDropsOffTheCard(): void
    {
        $this->configureMicroEntreprise();
        $period = $this->period(PeriodStatus::Closed, new DateTimeImmutable('today')->modify('-40 days'));
        $this->declaration($period, DeclarationStatus::Submitted);

        self::assertSame([], $this->widget()->getData()['pending']);
    }

    public function testTheOldestOutstandingPeriodComesFirst(): void
    {
        $this->configureMicroEntreprise();
        $this->period(PeriodStatus::Closed, new DateTimeImmutable('today')->modify('-40 days'), 2);
        $this->period(PeriodStatus::Closed, new DateTimeImmutable('today')->modify('-130 days'), 1);

        $pending = $this->widget()->getData()['pending'];

        self::assertCount(2, $pending);
        self::assertSame(130, $pending[0]->daysSincePeriodEnded());
        self::assertSame(40, $pending[1]->daysSincePeriodEnded());
    }

    /**
     * The quarter nothing was received in has no period row at all, and is the
     * one most easily forgotten. It is reported as one to create, because a nil
     * return cannot be filed against a period that does not exist.
     */
    public function testAQuarterThatWasNeverCreatedIsStillOutstanding(): void
    {
        $this->configureMicroEntreprise();
        $this->config->set(AccountingSettings::ACTIVITY_START_DATE, '2026-01-15');

        $pending = $this->widget()->getData()['pending'];

        self::assertNotSame([], $pending);
        self::assertSame(DeclarationAction::Create, $pending[0]->action);
        self::assertNull($pending[0]->period);
        self::assertSame('2026-Q1', $pending[0]->getLabel());
    }

    /**
     * A period still running has nothing to declare yet, so offering to create
     * it would be asking the user to act on something that is not over.
     */
    public function testTheQuarterStillRunningIsNotOfferedForCreation(): void
    {
        $this->configureMicroEntreprise();
        $this->config->set(AccountingSettings::ACTIVITY_START_DATE, new DateTimeImmutable('today')->format('Y-m-d'));

        self::assertSame([], $this->widget()->getData()['pending']);
    }

    public function testNamesWhereTheFiguresActuallyHaveToBeTyped(): void
    {
        $this->configureMicroEntreprise();

        self::assertNotNull($this->widget()->getData()['filingUrl']);
    }

    public function testRendersNothingOutstanding(): void
    {
        $this->configureMicroEntreprise();

        $this->assertMatchesHtmlSnapshot($this->render($this->widget()));
    }

    public function testRendersAPeriodWaitingToBeFiled(): void
    {
        $this->configureMicroEntreprise();
        // Relative to today, so the rendered "ended 40 days ago" is the same
        // sentence whenever this runs.
        $period = $this->period(PeriodStatus::Closed, new DateTimeImmutable('today')->modify('-40 days'));
        $this->declaration($period, DeclarationStatus::Ready);

        $this->assertMatchesHtmlSnapshot($this->render($this->widget()));
    }

    public function testGetTemplate(): void
    {
        self::assertSame(
            '@AugiasAccounting/Widget/declarations_due.html.twig',
            $this->widget()->getTemplate(),
        );
    }

    private function period(PeriodStatus $status, DateTimeImmutable $endDate, int $ordinal = 1): AccountingPeriod
    {
        $period = new AccountingPeriod()
            ->setType(PeriodType::Quarter)
            ->setYear((int) $endDate->format('Y'))
            ->setOrdinal($ordinal)
            ->setStartDate($endDate->modify('-3 months'))
            ->setEndDate($endDate)
            ->setStatus($status);

        $period->setCompany($this->companyReference());

        $this->entityManager->persist($period);
        $this->entityManager->flush();

        return $period;
    }

    private function declaration(AccountingPeriod $period, DeclarationStatus $status): Declaration
    {
        $declaration = new Declaration()
            ->setPeriod($period)
            ->setRegimeCode(MicroEntrepriseRegime::CODE)
            ->setStatus($status)
            ->setCurrencyCode('EUR')
            ->setTotalTurnover(BigInteger::of(1_000_000))
            ->setTotalContributions(BigInteger::of(220_000))
            ->setTotalDue(BigInteger::of(220_000));

        $declaration->setCompany($period->getCompany());

        $this->entityManager->persist($declaration);
        $this->entityManager->flush();

        return $declaration;
    }

    private function widget(): DeclarationsDueWidget
    {
        $widget = self::getContainer()->get(DeclarationsDueWidget::class);
        self::assertInstanceOf(DeclarationsDueWidget::class, $widget);

        return $widget;
    }
}
