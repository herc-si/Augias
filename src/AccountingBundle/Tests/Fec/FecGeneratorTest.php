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

namespace Augias\AccountingBundle\Tests\Fec;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Fec\FecGenerator;
use Augias\AccountingBundle\Fec\FecVariant;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_map;
use function explode;
use function str_replace;

/**
 * The FEC as the administration reads it (LPF art. A47 A-1): one balanced
 * entry per receipt or payment, numbered without a gap, on the right accounts.
 */
#[CoversClass(FecGenerator::class)]
final class FecGeneratorTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testTheBooksOfAYearAsBalancedEntries(): void
    {
        $this->activity(ActivityNature::ServicesBic);
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'SIRET', 'value' => '12345678900012']);

        $this->entry(LedgerBook::Revenue, '2026-03-10', 12000, 10000, 2000, SettlementMethod::BankTransfer, 'FACT-1');
        $this->entry(LedgerBook::Purchase, '2026-02-01', 6000, 5000, 1000, SettlementMethod::Cash, 'ACH-1', ActivityNature::SaleOfGoods);
        $this->entry(LedgerBook::Revenue, '2026-04-02', -2400, -2000, -400, SettlementMethod::BankTransfer, 'AV-1');
        $this->entry(LedgerBook::Expense, '2026-05-05', 3000, null, null, SettlementMethod::CreditCard, 'DEP-1');
        // Neither in the file: another year, and a journal that only dates VAT.
        $this->entry(LedgerBook::Revenue, '2025-12-31', 5000, 5000, 0, SettlementMethod::BankTransfer, 'OLD');
        $this->entry(LedgerBook::Sales, '2026-03-01', 2000, 0, 2000, null, 'VAT-ON-ISSUE');

        $fec = $this->generator()->generate($this->company, 2026);

        self::assertSame('123456789FEC20261231.txt', $fec->filename);
        self::assertSame(FecVariant::Standard, $fec->variant);
        self::assertSame(4, $fec->entries);

        $rows = $this->rows($fec->content);
        self::assertSame(FecVariant::Standard->fields(), array_keys($rows[0]));

        // Numbered 1 to 4 by date, each entry balanced to the cent.
        self::assertSame(['1', '2', '3', '4'], array_values(array_unique(array_column($rows, 'EcritureNum'))));
        foreach (array_unique(array_column($rows, 'EcritureNum')) as $number) {
            $lines = array_filter($rows, static fn (array $row): bool => $row['EcritureNum'] === $number);
            self::assertSame($this->sum($lines, 'Debit'), $this->sum($lines, 'Credit'), 'Entry ' . $number . ' balances.');
        }

        // 1: the cash purchase — goods and VAT debited, the till credited.
        self::assertSame(['AC', '20260201', '607', '50,00', '0,00'], $this->pick($rows, 'ACH-1', '607'));
        self::assertSame(['AC', '20260201', '44566', '10,00', '0,00'], $this->pick($rows, 'ACH-1', '44566'));
        self::assertSame(['AC', '20260201', '530', '0,00', '60,00'], $this->pick($rows, 'ACH-1', '530'));

        // 2: the receipt — bank debited, services and VAT credited.
        self::assertSame(['RE', '20260310', '512', '120,00', '0,00'], $this->pick($rows, 'FACT-1', '512'));
        self::assertSame(['RE', '20260310', '706', '0,00', '100,00'], $this->pick($rows, 'FACT-1', '706'));
        self::assertSame(['RE', '20260310', '44571', '0,00', '20,00'], $this->pick($rows, 'FACT-1', '44571'));

        // 3: a refund goes the other way.
        self::assertSame(['RE', '20260402', '512', '0,00', '24,00'], $this->pick($rows, 'AV-1', '512'));
        self::assertSame(['RE', '20260402', '706', '20,00', '0,00'], $this->pick($rows, 'AV-1', '706'));

        // 4: an expense without VAT is two lines.
        self::assertCount(2, array_filter($rows, static fn (array $row): bool => 'DEP-1' === $row['PieceRef']));

        self::assertStringNotContainsString('OLD', $fec->content);
        self::assertStringNotContainsString('VAT-ON-ISSUE', $fec->content);
        self::assertStringContainsString('44571', $fec->notice);
    }

    /**
     * A professional in BNC keeping cash accounts gets section VIII: when and
     * how each amount was settled, the nature of the operation, who with.
     */
    public function testABncProfessionalGetsTheCashAccountingFields(): void
    {
        $this->activity(ActivityNature::ServicesBnc);
        $this->entry(LedgerBook::Revenue, '2026-06-01', 12000, 10000, 2000, SettlementMethod::Check, 'H-1');

        $fec = $this->generator()->generate($this->company, 2026);
        $rows = $this->rows($fec->content);

        self::assertSame(FecVariant::CashBnc, $fec->variant);
        self::assertCount(22, $rows[0]);
        self::assertSame('20260601', $rows[0]['DateRglt']);
        self::assertSame('Chèque', $rows[0]['ModeRglt']);
        self::assertSame('Recette professionnelle', $rows[0]['NatOp']);
        self::assertSame('Client H', $rows[0]['IdClient']);
        self::assertStringContainsString('section VIII', $fec->notice);
        self::assertStringStartsWith('FEC20261231', $fec->filename, 'No SIREN known: the file still gets its date.');
    }

    private function entry(LedgerBook $book, string $date, int $amount, ?int $net, ?int $tax, ?SettlementMethod $method, string $reference, ActivityNature $nature = ActivityNature::ServicesBic): void
    {
        $entry = new LedgerEntry()
            ->setBook($book)
            ->setEntryDate(new DateTimeImmutable($date))
            ->setLabel('Entry ' . $reference)
            ->setCounterpartyName('Client H')
            ->setDocumentReference($reference)
            ->setAmount(BigInteger::of($amount))
            ->setCurrencyCode('EUR')
            ->setActivityNature($nature)
            ->setSettlementMethod($method);

        if (null !== $net && null !== $tax) {
            $entry->setTax(BigInteger::of($net), BigInteger::of($tax), []);
        }

        $entry->setCompany($this->company);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entry);
        $em->flush();
    }

    private function activity(ActivityNature $nature): void
    {
        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::PRIMARY_ACTIVITY, $nature->value);
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $content): array
    {
        $lines = explode("\r\n", rtrim($content, "\r\n"));
        $header = explode("\t", array_shift($lines));

        return array_map(static fn (string $line): array => array_combine($header, explode("\t", $line)), $lines);
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return list<string>
     */
    private function pick(array $rows, string $piece, string $account): array
    {
        foreach ($rows as $row) {
            if ($row['PieceRef'] === $piece && $row['CompteNum'] === $account) {
                return [$row['JournalCode'], $row['EcritureDate'], $row['CompteNum'], $row['Debit'], $row['Credit']];
            }
        }

        self::fail(sprintf('No line on %s for %s.', $account, $piece));
    }

    /**
     * @param array<array<string, string>> $lines
     */
    private function sum(array $lines, string $column): string
    {
        $total = 0;

        foreach ($lines as $line) {
            $total += (int) str_replace(',', '', $line[$column]);
        }

        return (string) $total;
    }

    private function generator(): FecGenerator
    {
        return self::getContainer()->get(FecGenerator::class);
    }
}
