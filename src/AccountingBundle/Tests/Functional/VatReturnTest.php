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
use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\DeclarationKind;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\DeclarationLine;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\DeclarationBuilder;
use Augias\AccountingBundle\Service\VatReturnCalculator;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The VAT return, gathered from the books rather than computed from a rate.
 */
#[CoversClass(VatReturnCalculator::class)]
#[CoversClass(DeclarationBuilder::class)]
#[Group('functional')]
final class VatReturnTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::SaleOfGoods->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
        // Past the franchise threshold: in the scope of VAT, and still a
        // micro-entreprise declaring turnover to URSSAF.
        $config->set(AccountingSettings::VAT_EXEMPT, '0');

        $this->period = self::getContainer()->get(AccountingPeriodManager::class)
            ->periodFor($this->companyReference(), PeriodType::Quarter, new DateTimeImmutable('2026-02-10'));

        $this->entityManager->flush();
    }

    /**
     * The two returns are not rivals: a micro-entrepreneur past the threshold
     * owes both for the same quarter, which is the whole reason a declaration
     * is no longer unique per period.
     */
    public function testAPeriodInTheScopeOfVatOwesTwoReturns(): void
    {
        $profile = self::getContainer()->get(AccountingProfileProvider::class)->forCompany($this->companyReference());

        $kinds = self::getContainer()->get(DeclarationBuilder::class)->kindsOwed($profile, $this->period);

        self::assertSame([DeclarationKind::SocialContributions, DeclarationKind::Vat], $kinds);
    }

    /**
     * Under the réel normal the same quarter owes only the VAT return. The
     * regime computes nothing on turnover — contributions there are assessed on
     * profit, which these cash-basis books do not produce — so offering a
     * turnover return would mean offering an empty one.
     */
    public function testAPeriodOnTheReelNormalOwesTheVatReturnAlone(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(AccountingSettings::REGIME, ReelNormalRegime::CODE);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);

        $profile = self::getContainer()->get(AccountingProfileProvider::class)->forCompany($this->companyReference());

        $kinds = self::getContainer()->get(DeclarationBuilder::class)->kindsOwed($profile, $this->period);

        self::assertSame([DeclarationKind::Vat], $kinds);
    }

    public function testCollectedVatIsReportedPerRateAndDeductedAsOneFigure(): void
    {
        $this->sale(120_000, [['rate' => '20.0000', 'category' => 'Standard', 'base' => '100000', 'tax' => '20000']]);
        $this->sale(105_500, [['rate' => '5.5000', 'category' => 'Standard', 'base' => '100000', 'tax' => '5500']]);
        $this->purchase(60_000, 10_000);

        $result = self::getContainer()->get(VatReturnCalculator::class)->calculate($this->period, 'EUR');

        self::assertCount(3, $result->lines);

        // Sorted by rate, so the return reads in the order its boxes do.
        self::assertSame('5.5000', (string) $result->lines[0]->rate);
        self::assertSame('5500', (string) $result->lines[0]->amount);
        self::assertSame('20.0000', (string) $result->lines[1]->rate);
        self::assertSame('20000', (string) $result->lines[1]->amount);

        $deducted = $result->lines[2];

        self::assertSame(DeclarationLine::KIND_VAT_DEDUCTIBLE, $deducted->kind);
        self::assertSame('-10000', (string) $deducted->amount, 'Deducted VAT is negative, so the lines add up to the balance.');

        // 25 500 collected less 10 000 deducted.
        self::assertSame('15500', (string) $result->totalDue());
        self::assertSame('200000', (string) $result->turnover, 'The taxable base, not the money received.');
    }

    /**
     * More deducted than collected is not a debt owed backwards — it is a
     * credit, and the figure has to come out negative for the total to say so.
     */
    public function testDeductingMoreThanWasCollectedProducesACredit(): void
    {
        $this->sale(12_000, [['rate' => '20.0000', 'category' => 'Standard', 'base' => '10000', 'tax' => '2000']]);
        $this->purchase(60_000, 10_000);

        $result = self::getContainer()->get(VatReturnCalculator::class)->calculate($this->period, 'EUR');

        self::assertSame('-8000', (string) $result->totalDue());
    }

    public function testNothingTaxableInThePeriodProducesNoLines(): void
    {
        $result = self::getContainer()->get(VatReturnCalculator::class)->calculate($this->period, 'EUR');

        self::assertSame([], $result->lines);
        self::assertSame('0', (string) $result->totalDue());
    }

    /**
     * Stored alongside the turnover declaration rather than instead of it, each
     * with its own status and its own reference to record.
     */
    public function testBothReturnsAreStoredForTheSamePeriod(): void
    {
        $this->sale(120_000, [['rate' => '20.0000', 'category' => 'Standard', 'base' => '100000', 'tax' => '20000']]);

        $builder = self::getContainer()->get(DeclarationBuilder::class);

        $social = $builder->forPeriod($this->period, DeclarationKind::SocialContributions);
        $vat = $builder->forPeriod($this->period, DeclarationKind::Vat);

        self::assertNotSame($social->getId(), $vat->getId());
        self::assertSame(DeclarationKind::Vat, $vat->getKind());
        self::assertSame('20000', (string) $vat->getTotalDue());
        self::assertNull($vat->getRateVersion(), 'VAT comes from the documents, not from a rate table that can go stale.');
    }

    /**
     * The case this was built for: a micro-entrepreneur past the franchise
     * threshold lands on the régime réel simplifié, which wants one VAT return
     * a year, while URSSAF still wants turnover every quarter. The books are
     * still sealed quarterly — a VAT cycle groups figures for a return, it does
     * not open a second set of registers.
     */
    public function testVatCanBeDeclaredYearlyWhileTheBooksAreSealedQuarterly(): void
    {
        self::getContainer()->get(SystemConfig::class)
            ->set(AccountingSettings::VAT_PERIODICITY, PeriodType::Year->value);

        // One sale in Q1 and one in Q3, each filed into its own quarter.
        $this->sale(120_000, [['rate' => '20.0000', 'category' => 'Standard', 'base' => '100000', 'tax' => '20000']]);
        $q3 = self::getContainer()->get(AccountingPeriodManager::class)
            ->periodFor($this->companyReference(), PeriodType::Quarter, new DateTimeImmutable('2026-08-10'));
        $this->entityManager->flush();

        $this->period = $q3;
        $this->sale(60_000, [['rate' => '20.0000', 'category' => 'Standard', 'base' => '50000', 'tax' => '10000']]);

        $year = self::getContainer()->get(AccountingPeriodManager::class)
            ->periodFor($this->companyReference(), PeriodType::Year, new DateTimeImmutable('2026-06-15'));
        $this->entityManager->flush();

        $profile = self::getContainer()->get(AccountingProfileProvider::class)->forCompany($this->companyReference());
        $builder = self::getContainer()->get(DeclarationBuilder::class);

        // Each period serves the cycle it belongs to, and only that one.
        self::assertSame([DeclarationKind::SocialContributions], $builder->kindsOwed($profile, $q3));
        self::assertSame([DeclarationKind::Vat], $builder->kindsOwed($profile, $year));

        // The yearly return gathers both quarters, because it goes by the date
        // the money moved and not by the period each entry was filed into.
        $result = self::getContainer()->get(VatReturnCalculator::class)->calculate($year, 'EUR');

        self::assertSame('30000', (string) $result->totalDue());
        self::assertSame('150000', (string) $result->turnover);
    }

    /**
     * What the financial year setting is actually for. A company whose exercice
     * opens in April and declares VAT yearly declares April to March — not a
     * calendar year it straddles, and not two half-returns.
     */
    public function testAYearlyReturnFollowsTheFinancialYearRatherThanTheCalendar(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(AccountingSettings::VAT_PERIODICITY, PeriodType::Year->value);
        $config->set(AccountingSettings::FISCAL_YEAR_START_MONTH, '4');

        $manager = self::getContainer()->get(AccountingPeriodManager::class);
        $profile = self::getContainer()->get(AccountingProfileProvider::class)->forCompany($this->companyReference());

        // A sale in March 2027 — inside the exercice that opened in April 2026,
        // and outside the calendar year the date belongs to.
        $this->period = $manager->periodFor(
            $this->companyReference(),
            PeriodType::Quarter,
            new DateTimeImmutable('2027-03-15'),
        );
        $this->entityManager->flush();
        $this->saleOn('2027-03-15', 60_000, [['rate' => '20.0000', 'category' => 'Standard', 'base' => '50000', 'tax' => '10000']]);

        $exercice = $manager->periodFor(
            $this->companyReference(),
            PeriodType::Year,
            new DateTimeImmutable('2026-09-01'),
            $profile->fiscalYearStartMonth,
        );
        $this->entityManager->flush();

        self::assertSame('2026-04-01', $exercice->getStartDate()->format('Y-m-d'));
        self::assertSame('2027-03-31', $exercice->getEndDate()->format('Y-m-d'));
        self::assertSame('2026-2027', $exercice->getLabel());

        $result = self::getContainer()->get(VatReturnCalculator::class)->calculate($exercice, 'EUR');

        self::assertSame('10000', (string) $result->totalDue(), 'The March sale falls inside the exercice.');
    }

    /**
     * @param list<array{rate: string, category: string, base: string, tax: string}> $breakdown
     */
    private function sale(int $amount, array $breakdown): void
    {
        $this->saleOn('2026-02-10', $amount, $breakdown);
    }

    /**
     * @param list<array{rate: string, category: string, base: string, tax: string}> $breakdown
     */
    private function saleOn(string $on, int $amount, array $breakdown): void
    {
        $tax = BigInteger::zero();

        foreach ($breakdown as $share) {
            $tax = $tax->plus($share['tax']);
        }

        $entry = $this->entry(LedgerBook::Revenue, $amount, $on);
        $entry->setActivityNature(ActivityNature::SaleOfGoods)
            ->setTax(BigInteger::of($amount)->minus($tax), $tax, $breakdown);

        $this->entityManager->flush();
    }

    private function purchase(int $amount, int $tax): void
    {
        $this->entry(LedgerBook::Purchase, $amount)
            ->setTax(BigInteger::of($amount - $tax), BigInteger::of($tax), []);

        $this->entityManager->flush();
    }

    private function entry(LedgerBook $book, int $amount, string $on = '2026-02-10'): LedgerEntry
    {
        $entry = new LedgerEntry()
            ->setBook($book)
            ->setEntryDate(new DateTimeImmutable($on))
            ->setLabel('Operation')
            ->setCounterpartyName('Someone')
            ->setAmount(BigInteger::of($amount))
            ->setCurrencyCode('EUR')
            ->setPeriod($this->period);

        $entry->setCompany($this->companyReference());

        $this->entityManager->persist($entry);

        return $entry;
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
