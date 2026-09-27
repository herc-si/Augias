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

namespace Augias\AccountingBundle\Tests\Bank;

use Augias\AccountingBundle\Bank\ParsedTransaction;
use Augias\AccountingBundle\Bank\Parser\CamtParser;
use Augias\AccountingBundle\Bank\Parser\CsvParser;
use Augias\AccountingBundle\Bank\Parser\OfxParser;
use Augias\AccountingBundle\Bank\Parser\StatementText;
use Augias\AccountingBundle\Bank\UnreadableStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function file_get_contents;
use function mb_convert_encoding;

/**
 * The three formats banks hand out, as they actually write them.
 */
#[CoversClass(CamtParser::class)]
#[CoversClass(OfxParser::class)]
#[CoversClass(CsvParser::class)]
#[CoversClass(StatementText::class)]
final class ParserTest extends TestCase
{
    public function testCamt053(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/Fixtures/camt053.xml');
        $parser = new CamtParser();

        self::assertTrue($parser->supports($content));
        $lines = $parser->parse($content);

        self::assertCount(2, $lines);
        $this->assertLine($lines[0], '2026-09-24', '1200.00', 'VIR FACT-2026-0042', 'ACME SARL', 'BQ-0001');
        self::assertSame('EUR', $lines[0]->currency);
        // Debit made negative; a date-time booking date read as its day; no remittance: the additional information.
        $this->assertLine($lines[1], '2026-09-25', '-49.90', 'PRLV SEPA HEBERGEMENT', 'Hébergeur SA', 'BQ-0002');
    }

    public function testOfxInItsSgmlVersion(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/Fixtures/statement.ofx');
        $parser = new OfxParser();

        self::assertTrue($parser->supports($content));
        self::assertFalse(new CamtParser()->supports($content));
        $lines = $parser->parse($content);

        self::assertCount(2, $lines);
        $this->assertLine($lines[0], '2026-09-24', '1200.00', 'VIR ACME SARL FACT-2026-0042', 'VIR ACME SARL', '2026092400001');
        $this->assertLine($lines[1], '2026-09-25', '-49.90', 'PRLV HEBERGEUR', 'PRLV HEBERGEUR', '2026092500002');
        self::assertSame('EUR', $lines[1]->currency);
    }

    /**
     * A French bank: preamble lines, semicolons, decimal commas, separate
     * debit and credit columns — debit written positive.
     */
    public function testCsvWithDebitAndCreditColumns(): void
    {
        $csv = "Compte courant;FR76 3000 6000 0112 3456 7890 189\nSolde au 26/09/2026;5 432,10\n\nDate opération;Libellé;Débit;Crédit\n24/09/2026;VIR ACME SARL FACT-2026-0042;;1 200,00\n25/09/2026;PRLV HEBERGEUR;49,90;\n";
        $lines = new CsvParser()->parse($csv);

        self::assertCount(2, $lines);
        $this->assertLine($lines[0], '2026-09-24', '1200.00', 'VIR ACME SARL FACT-2026-0042');
        $this->assertLine($lines[1], '2026-09-25', '-49.90', 'PRLV HEBERGEUR');
    }

    public function testCsvWithOneSignedAmountColumn(): void
    {
        $csv = "\"Booking date\",\"Description\",\"Amount\"\n\"2026-09-24\",\"Transfer, ACME\",\"1,200.00\"\n\"2026-09-25\",\"Hosting\",\"-49.90\"\n";
        $lines = new CsvParser()->parse($csv);

        self::assertCount(2, $lines);
        $this->assertLine($lines[0], '2026-09-24', '1200.00', 'Transfer, ACME');
        $this->assertLine($lines[1], '2026-09-25', '-49.90', 'Hosting');
    }

    public function testACsvWithoutRecognisableColumnsIsRefused(): void
    {
        $this->expectException(UnreadableStatement::class);

        new CsvParser()->parse("a;b;c\n1;2;3\n");
    }

    public function testWindows1252IsReadAsUtf8(): void
    {
        $content = (string) mb_convert_encoding("Date;Libellé;Montant\n24/09/2026;Café;-3,50\n", 'Windows-1252', 'UTF-8');
        $lines = new CsvParser()->parse(StatementText::utf8($content));

        $this->assertLine($lines[0], '2026-09-24', '-3.50', 'Café');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'French' => ['1 234,56', '1234.56'];
        yield 'English' => ['1,234.56', '1234.56'];
        yield 'negative French' => ['-49,90', '-49.90'];
        yield 'with a currency sign' => ['12,50 €', '12.50'];
        yield 'no decimals' => ['1200', '1200'];
    }

    #[DataProvider('amounts')]
    public function testAmounts(string $written, string $read): void
    {
        self::assertSame($read, (string) StatementText::amount($written));
    }

    public function testAnUnreadableDateIsSaid(): void
    {
        $this->expectException(UnreadableStatement::class);

        StatementText::date('26 septembre');
    }

    private function assertLine(ParsedTransaction $line, string $date, string $amount, string $label, ?string $counterparty = null, ?string $reference = null): void
    {
        self::assertSame($date, $line->date->format('Y-m-d'));
        self::assertTrue($line->amount->isEqualTo($amount), $line->amount . ' ≠ ' . $amount);
        self::assertSame($label, $line->label);
        self::assertSame($counterparty, $line->counterparty);
        self::assertSame($reference, $line->reference);
    }
}
