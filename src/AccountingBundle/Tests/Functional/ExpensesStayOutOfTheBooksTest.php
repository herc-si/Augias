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
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\TurnoverCalculator;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An expense is kept for its owner, not for the administration.
 *
 * The expenses book exists so a receipt has somewhere to go; it is not a
 * statutory book, and nothing it holds may reach a turnover figure, a period's
 * totals or a VAT return. Both places that add up a period's entries treated
 * "not a purchase" as "a receipt", so the guard is worth pinning.
 */
#[CoversClass(AccountingPeriodManager::class)]
#[CoversClass(TurnoverCalculator::class)]
#[Group('functional')]
final class ExpensesStayOutOfTheBooksTest extends KernelTestCase
{
    use DoctrineTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    public function testAnExpenseIsNotTurnover(): void
    {
        $this->record(LedgerBook::Revenue, 100_000, null);
        $this->record(LedgerBook::Expense, 30_000, 5_000);

        $now = new DateTimeImmutable();

        $turnover = self::getContainer()->get(TurnoverCalculator::class)->forRange(
            $this->company,
            'EUR',
            $now->modify('-1 day'),
            $now->modify('+1 day'),
        );

        self::assertSame('100000', (string) $turnover->total());
    }

    public function testSealingAPeriodLeavesExpensesAndTheirTaxOutOfIt(): void
    {
        $revenue = $this->record(LedgerBook::Revenue, 100_000, null);
        $this->record(LedgerBook::Expense, 30_000, 5_000);

        $period = $revenue->getPeriod();
        self::assertInstanceOf(AccountingPeriod::class, $period);

        self::getContainer()->get(AccountingPeriodManager::class)
            ->close($period, null, $period->getEndDate()->modify('+1 day'));

        $totals = $period->getTotals();

        self::assertSame(
            ['services_bnc' => '100000'],
            $totals['revenue'] ?? null,
            'The expense must not swell the turnover of the period.',
        );
        self::assertArrayNotHasKey('tax_collected', $totals, 'VAT on an expense was never collected from anyone.');
    }

    private function record(LedgerBook $book, int $amount, ?int $tax): LedgerEntry
    {
        $entry = new LedgerEntry()
            ->setCompany($this->company)
            ->setBook($book)
            ->setSource(LedgerEntrySource::Manual)
            ->setCurrencyCode('EUR')
            ->setEntryDate(new DateTimeImmutable())
            ->setLabel($book === LedgerBook::Expense ? 'Billet de train' : 'Prestation')
            ->setAmount(BigInteger::of($amount));

        if ($book === LedgerBook::Revenue) {
            $entry->setActivityNature(ActivityNature::ServicesBnc);
        }

        if ($tax !== null) {
            $entry->setTax(BigInteger::of($amount - $tax), BigInteger::of($tax), []);
        }

        self::getContainer()->get(AccountingPeriodManager::class)
            ->assignPeriod($entry, PeriodType::Quarter);

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
