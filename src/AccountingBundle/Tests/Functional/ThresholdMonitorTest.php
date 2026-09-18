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

namespace Augias\AccountingBundle\Tests\Functional;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\RaisedThreshold;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Repository\ThresholdAlertRepository;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\ThresholdMonitor;
use Augias\AccountingBundle\Service\TurnoverCalculator;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_map;

#[CoversClass(ThresholdMonitor::class)]
#[CoversClass(TurnoverCalculator::class)]
final class ThresholdMonitorTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    /**
     * The BNC services ceiling, in cents. Read from the rate table rather than
     * repeated here would test the table against itself; this is the figure the
     * fixtures are sized against, and a test that fails when it changes is
     * doing its job.
     */
    /** The BNC and BIC services ceiling for 2026-2028, in cents. */
    private const int SERVICES_CEILING = 8_360_000;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;

        $config = self::getContainer()->get(SystemConfig::class);
        // The books are kept in the company's own currency, so the fixtures
        // have to agree with it — see testTurnoverInAnotherCurrencyIsCountedApart.
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    public function testTheCeilingIsTheOneTheRateTableHolds(): void
    {
        $regime = self::getContainer()->get(MicroEntrepriseRegime::class);
        $profile = self::getContainer()->get(AccountingProfileProvider::class)
            ->forCompany($this->company);

        $ceiling = $regime->thresholds($profile, new DateTimeImmutable('2026-06-30'))
            ->get('micro_ceiling.services_bnc');

        self::assertNotNull($ceiling);
        self::assertSame((string) self::SERVICES_CEILING, (string) $ceiling->amount);
    }

    public function testNothingIsRaisedWellBelowEveryLimit(): void
    {
        $this->revenue(1_000_00, new DateTimeImmutable('2026-02-10'));

        self::assertSame([], $this->check());
    }

    /**
     * 85% of the ceiling is also well past both VAT thresholds, which sit at
     * roughly half of it, so more than one limit is reported at once. The
     * assertion names the one under test rather than the whole set: which other
     * limits a regime publishes is the rate table's business, not this test's.
     */
    public function testApproachingTheCeilingRaisesTheEightyPercentMilestone(): void
    {
        $this->revenue((int) (self::SERVICES_CEILING * 0.85), new DateTimeImmutable('2026-02-10'));

        $raised = $this->check();
        $keys = $this->keysAndSteps($raised);

        self::assertContains('micro_ceiling.services_bnc:80', $keys);
    }

    public function testPassingTheCeilingRaisesTheHundredPercentMilestoneInstead(): void
    {
        $this->revenue(self::SERVICES_CEILING + 1, new DateTimeImmutable('2026-02-10'));

        $keys = $this->keysAndSteps($this->check());

        self::assertContains('micro_ceiling.services_bnc:100', $keys);
        self::assertNotContains(
            'micro_ceiling.services_bnc:80',
            $keys,
            'A company that leaps past both milestones at once is told about the crossing, not about approaching it.',
        );
    }

    public function testAMilestoneIsRaisedOnlyOncePerYear(): void
    {
        $this->revenue((int) (self::SERVICES_CEILING * 0.85), new DateTimeImmutable('2026-02-10'));

        self::assertNotSame([], $this->check());
        self::assertSame([], $this->check(), 'The daily job must not report the same milestone twice.');
    }

    /**
     * Turnover of one kind is measured against that kind's own limits, not
     * against every limit the regime publishes.
     */
    public function testALimitIsMeasuredAgainstItsOwnActivity(): void
    {
        $this->revenue(self::SERVICES_CEILING + 1, new DateTimeImmutable('2026-02-10'));

        $keys = $this->keysAndSteps($this->check());

        self::assertNotContains('micro_ceiling.sale_of_goods:100', $keys);
        self::assertNotContains('micro_ceiling.sale_of_goods:80', $keys);
    }

    public function testACompanyThatKeepsNoBooksIsNeverAlerted(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::REGIME, '');

        $this->revenue(self::SERVICES_CEILING + 1, new DateTimeImmutable('2026-02-10'));

        self::assertSame([], $this->check());
    }

    /**
     * Cumulative over the year, which is what the limits are expressed in — not
     * the period's own figure.
     */
    public function testTurnoverIsCountedFromTheFirstOfJanuary(): void
    {
        $this->revenue(30_000_00, new DateTimeImmutable('2026-01-20'));
        $this->revenue(40_000_00, new DateTimeImmutable('2026-06-15'));
        $this->revenue(99_999_00, new DateTimeImmutable('2025-12-31'));

        $turnover = $this->turnoverCalculator()
            ->yearToDate($this->companyReference(), 'EUR', new DateTimeImmutable('2026-06-30'));

        self::assertSame('7000000', (string) $turnover->total());
    }

    /**
     * The first of January is inside the year, which sounds too obvious to test
     * until you see how it was lost: the range was bound as a datetime against
     * a DATE column, so on SQLite `2026-01-01` compared as a string against
     * `2026-01-01 00:00:00` fell outside its own year.
     */
    public function testTurnoverBookedOnTheOpeningDayOfTheYearIsCounted(): void
    {
        $this->revenue(30_000_00, new DateTimeImmutable('2026-01-01'));

        $turnover = $this->turnoverCalculator()
            ->yearToDate($this->companyReference(), 'EUR', new DateTimeImmutable('2026-06-30'));

        self::assertSame('3000000', (string) $turnover->total());
    }

    /**
     * Converting would invent an exchange rate the books never recorded, so
     * turnover in another currency is left out of the totals and named instead.
     */
    public function testTurnoverInAnotherCurrencyIsCountedApart(): void
    {
        $this->revenue(30_000_00, new DateTimeImmutable('2026-01-20'));
        $this->revenue(50_000_00, new DateTimeImmutable('2026-02-20'), 'USD');

        $turnover = $this->turnoverCalculator()
            ->yearToDate($this->companyReference(), 'EUR', new DateTimeImmutable('2026-06-30'));

        self::assertSame('3000000', (string) $turnover->total());
        self::assertTrue($turnover->hasForeignCurrencies());
        self::assertSame(['USD'], $turnover->foreignCurrencies);
    }

    /**
     * @param list<RaisedThreshold> $raised
     * @return list<string>
     */
    private function keysAndSteps(array $raised): array
    {
        return array_map(
            static fn (RaisedThreshold $r): string => $r->threshold->key . ':' . $r->alert->getStep(),
            $raised,
        );
    }

    /**
     * @return list<RaisedThreshold>
     */
    private function check(): array
    {
        return $this->monitor()->check($this->companyReference(), new DateTimeImmutable('2026-06-30'));
    }

    /**
     * Built by hand rather than pulled from the container: both services have a
     * single consumer each, so the container inlines them and there is nothing
     * left to fetch by class name. Their dependencies are all registered.
     */
    private function monitor(): ThresholdMonitor
    {
        return new ThresholdMonitor(
            $this->entityManager,
            self::getContainer()->get(AccountingProfileProvider::class),
            self::getContainer()->get(RegimeRegistry::class),
            $this->turnoverCalculator(),
            self::getContainer()->get(ThresholdAlertRepository::class),
        );
    }

    private function turnoverCalculator(): TurnoverCalculator
    {
        return new TurnoverCalculator(self::getContainer()->get(LedgerEntryRepository::class));
    }

    private function revenue(int $amount, DateTimeImmutable $on, string $currencyCode = 'EUR'): void
    {
        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setEntryDate($on)
            ->setLabel('Invoice payment')
            ->setCounterpartyName('Johnston PLC')
            ->setAmount(BigInteger::of($amount))
            ->setCurrencyCode($currencyCode)
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
