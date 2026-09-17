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

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Service\VatFilingRhythm;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The four-quarter window the 4 000 € tolerance is measured over.
 *
 * Every case here is about *which* entries are counted, because that is the
 * whole difficulty: the window is not the calendar year, it does not include
 * the quarter being lived, and it moves on the first day of every quarter.
 */
#[CoversClass(VatFilingRhythm::class)]
final class VatFilingRhythmTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * Asked in May, the window is the four quarters that ended in March — not
     * the twelve months to yesterday, and not the calendar year.
     */
    public function testTheWindowIsTheFourQuartersBeforeTheCurrentOne(): void
    {
        $window = $this->rhythm()->trailingYear(new DateTimeImmutable('2026-05-14'));

        self::assertSame('2025-04-01', $window['from']->format('Y-m-d'));
        self::assertSame('2026-03-31', $window['to']->format('Y-m-d'));
    }

    /**
     * On the first day of a quarter the window has just moved, and the quarter
     * that ended the day before is in it.
     */
    public function testTheWindowMovesOnTheFirstDayOfAQuarter(): void
    {
        $window = $this->rhythm()->trailingYear(new DateTimeImmutable('2026-07-01'));

        self::assertSame('2025-07-01', $window['from']->format('Y-m-d'));
        self::assertSame('2026-06-30', $window['to']->format('Y-m-d'));
    }

    /**
     * Both entries sit on a boundary day, deliberately. The first of them is
     * what caught {@see \Augias\AccountingBundle\Repository\LedgerEntryRepository::taxForRange()}
     * binding its dates as datetimes: against a DATE column on SQLite that
     * dropped everything booked on the opening day of a range.
     */
    public function testCountsTheTaxCollectedInsideTheWindow(): void
    {
        $this->sale('2025-04-01', 150_000);
        $this->sale('2026-03-31', 150_000);

        self::assertSame('300000', (string) $this->taxOn('2026-05-14'));
    }

    /**
     * The quarter in progress is excluded, and so is the quarter that dropped
     * out of the far end. Both boundaries matter: including the current one
     * would announce a crossing on a quarter that is not over, and keeping the
     * fifth would measure fifteen months.
     */
    public function testIgnoresTheCurrentQuarterAndAnythingOlderThanTheWindow(): void
    {
        $this->sale('2025-03-31', 500_000);
        $this->sale('2026-04-02', 500_000);

        self::assertSame('0', (string) $this->taxOn('2026-05-14'));
    }

    /**
     * Net, not collected: what the tolerance is measured on is the tax payable,
     * so what was reclaimed on purchases comes off.
     */
    public function testDeductedTaxComesOffTheFigure(): void
    {
        $this->sale('2025-06-30', 450_000);
        $this->purchase('2025-06-30', 100_000);

        self::assertSame('350000', (string) $this->taxOn('2026-05-14'));
    }

    /**
     * More reclaimed than charged is a credit, not a debt owed backwards — and
     * a company in that position is nowhere near the ceiling.
     */
    public function testACreditPositionStaysUnderTheCeiling(): void
    {
        $this->sale('2025-06-30', 100_000);
        $this->purchase('2025-06-30', 250_000);

        self::assertSame('-150000', (string) $this->taxOn('2026-05-14'));
        self::assertTrue($this->rhythm()->mayFileQuarterly($this->companyReference(), new DateTimeImmutable('2026-05-14')));
    }

    /**
     * 4 000 € exactly is over the line, not on the right side of it: the
     * tolerance is for tax payable *below* the ceiling.
     */
    public function testTheCeilingItselfClosesTheTolerance(): void
    {
        $this->sale('2025-06-30', VatFilingRhythm::QUARTERLY_CEILING);

        self::assertFalse($this->rhythm()->mayFileQuarterly($this->companyReference(), new DateTimeImmutable('2026-05-14')));
    }

    public function testACentUnderTheCeilingKeepsIt(): void
    {
        $this->sale('2025-06-30', VatFilingRhythm::QUARTERLY_CEILING - 1);

        self::assertTrue($this->rhythm()->mayFileQuarterly($this->companyReference(), new DateTimeImmutable('2026-05-14')));
    }

    private function taxOn(string $date): BigInteger
    {
        return $this->rhythm()->taxOverTheTrailingYear($this->companyReference(), new DateTimeImmutable($date));
    }

    private function sale(string $on, int $tax): void
    {
        $entry = $this->entry(LedgerBook::Revenue, $on, $tax * 6);
        $entry->setActivityNature(ActivityNature::ServicesBnc)
            ->setTax(
                BigInteger::of($tax * 6)->minus($tax),
                BigInteger::of($tax),
                [['rate' => '20.0000', 'category' => 'Standard', 'base' => (string) ($tax * 5), 'tax' => (string) $tax]],
            );

        $this->entityManager->flush();
    }

    private function purchase(string $on, int $tax): void
    {
        $this->entry(LedgerBook::Purchase, $on, $tax * 6)
            ->setTax(BigInteger::of($tax * 5), BigInteger::of($tax), []);

        $this->entityManager->flush();
    }

    private function entry(LedgerBook $book, string $on, int $amount): LedgerEntry
    {
        $entry = new LedgerEntry()
            ->setBook($book)
            ->setEntryDate(new DateTimeImmutable($on))
            ->setLabel('Operation')
            ->setCounterpartyName('Someone')
            ->setAmount(BigInteger::of($amount))
            ->setCurrencyCode('EUR');

        $entry->setCompany($this->companyReference());

        $this->entityManager->persist($entry);

        return $entry;
    }

    private function rhythm(): VatFilingRhythm
    {
        $rhythm = self::getContainer()->get(VatFilingRhythm::class);
        self::assertInstanceOf(VatFilingRhythm::class, $rhythm);

        return $rhythm;
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
