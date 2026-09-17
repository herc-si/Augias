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

namespace Augias\AccountingBundle\Tests\Service;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodStatus;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Model\MissingPeriod;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\PeriodCalendar;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PeriodCalendar::class)]
final class PeriodCalendarTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    private SystemConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $config = self::getContainer()->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);
        $this->config = $config;

        $this->config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $this->config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    /**
     * A company with no start date and nothing in the books has nothing showing
     * it ever traded, and inventing nil quarters for it would be inventing an
     * obligation. Only the period it is in counts, and that one has not ended.
     */
    public function testACompanyWithNoHistoryIsOwedNothing(): void
    {
        $missing = $this->calendar()->missing($this->company, $this->profile(), new DateTimeImmutable('2026-08-10'));

        self::assertCount(1, $missing);
        self::assertSame('2026-Q3', $missing[0]->getLabel());
        self::assertFalse($missing[0]->hasEnded(new DateTimeImmutable('2026-08-10')));
    }

    public function testWalksEveryQuarterSinceTheActivityStarted(): void
    {
        $this->config->set(AccountingSettings::ACTIVITY_START_DATE, '2026-01-15');

        $missing = $this->calendar()->missing($this->company, $this->profile(), new DateTimeImmutable('2026-08-10'));

        self::assertSame(
            ['2026-Q1', '2026-Q2', '2026-Q3'],
            array_map(static fn (MissingPeriod $period): string => $period->getLabel(), $missing),
        );
    }

    public function testAQuarterThatAlreadyHasARowIsNotMissing(): void
    {
        $this->config->set(AccountingSettings::ACTIVITY_START_DATE, '2026-01-15');
        $this->period(2026, 2, '2026-04-01', '2026-06-30');

        $missing = $this->calendar()->missing($this->company, $this->profile(), new DateTimeImmutable('2026-08-10'));

        self::assertSame(
            ['2026-Q1', '2026-Q3'],
            array_map(static fn (MissingPeriod $period): string => $period->getLabel(), $missing),
        );
    }

    /**
     * A company that started trading on the last day of a quarter still has a
     * row for that quarter, and it is not missing.
     *
     * The walk begins on the first day of trading, so that date is also the
     * lower bound of the lookup — and the existing row ends on it exactly. The
     * bound was a datetime against a DATE column, which on SQLite put the row
     * outside its own range: the quarter was reported missing for ever, on a
     * screen that offers to create what already exists.
     */
    public function testAQuarterEndingOnTheFirstDayOfTradingIsNotMissing(): void
    {
        $this->config->set(AccountingSettings::ACTIVITY_START_DATE, '2026-03-31');
        $this->period(2026, 1, '2026-01-01', '2026-03-31');

        $missing = $this->calendar()->missing($this->company, $this->profile(), new DateTimeImmutable('2026-05-10'));

        self::assertSame(
            ['2026-Q2'],
            array_map(static fn (MissingPeriod $period): string => $period->getLabel(), $missing),
        );
    }

    /**
     * Without a declared start date the oldest entry is the earliest date there
     * is evidence the business was trading on.
     */
    public function testFallsBackToTheOldestEntryInTheBooks(): void
    {
        $this->revenue(new DateTimeImmutable('2026-04-20'));

        $missing = $this->calendar()->missing($this->company, $this->profile(), new DateTimeImmutable('2026-08-10'));

        // Both quarters from the entry's own onwards: persisting an entry does
        // not itself create a period — only filing one through the period
        // manager does — so nothing here has a row yet.
        self::assertSame(
            ['2026-Q2', '2026-Q3'],
            array_map(static fn (MissingPeriod $period): string => $period->getLabel(), $missing),
        );
    }

    /**
     * The declared start of activity wins over the books: a company can have
     * traded for two quarters before it recorded anything, and those quarters
     * still have to be declared.
     *
     * Without the start date the oldest entry here would put the walk at Q3.
     */
    public function testTheDeclaredStartDateBeatsTheOldestEntry(): void
    {
        $this->config->set(AccountingSettings::ACTIVITY_START_DATE, '2026-01-15');
        $this->revenue(new DateTimeImmutable('2026-07-20'));

        $missing = $this->calendar()->missing($this->company, $this->profile(), new DateTimeImmutable('2026-08-10'));

        self::assertSame(
            ['2026-Q1', '2026-Q2', '2026-Q3'],
            array_map(static fn (MissingPeriod $period): string => $period->getLabel(), $missing),
        );
    }

    /**
     * A start date from the last century is bad data, and walking a hundred
     * years of quarters to discover that helps nobody.
     */
    public function testAnAbsurdStartDateIsBounded(): void
    {
        $this->config->set(AccountingSettings::ACTIVITY_START_DATE, '1970-01-01');

        $missing = $this->calendar()->missing($this->company, $this->profile(), new DateTimeImmutable('2026-08-10'));

        self::assertLessThanOrEqual(60, count($missing));
    }

    private function calendar(): PeriodCalendar
    {
        $calendar = self::getContainer()->get(PeriodCalendar::class);
        self::assertInstanceOf(PeriodCalendar::class, $calendar);

        return $calendar;
    }

    private function profile(): AccountingProfile
    {
        $provider = self::getContainer()->get(AccountingProfileProvider::class);
        self::assertInstanceOf(AccountingProfileProvider::class, $provider);

        return $provider->forCompany($this->companyReference());
    }

    private function period(int $year, int $ordinal, string $start, string $end): void
    {
        $period = new AccountingPeriod()
            ->setType(PeriodType::Quarter)
            ->setYear($year)
            ->setOrdinal($ordinal)
            ->setStartDate(new DateTimeImmutable($start))
            ->setEndDate(new DateTimeImmutable($end))
            ->setStatus(PeriodStatus::Open);

        $period->setCompany($this->companyReference());

        $this->entityManager->persist($period);
        $this->entityManager->flush();
    }

    private function revenue(DateTimeImmutable $on): void
    {
        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setEntryDate($on)
            ->setLabel('Invoice payment')
            ->setCounterpartyName('Johnston PLC')
            ->setAmount(BigInteger::of(100_000))
            ->setCurrencyCode('EUR')
            ->setActivityNature(ActivityNature::ServicesBnc);

        $entry->setCompany($this->companyReference());

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
