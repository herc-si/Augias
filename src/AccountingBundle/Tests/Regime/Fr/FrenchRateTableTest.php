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

namespace Augias\AccountingBundle\Tests\Regime\Fr;

use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PensionFund;
use Augias\AccountingBundle\Regime\Fr\FrenchRateTable;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function dirname;
use function file_put_contents;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

#[CoversClass(FrenchRateTable::class)]
final class FrenchRateTableTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->tempFiles = [];
    }

    /**
     * The shipped file is what production actually reads, so it has to answer
     * every lookup the calculator makes.
     */
    public function testShippedTableAnswersEveryLookup(): void
    {
        $table = $this->shippedTable();

        $on = new DateTimeImmutable('2026-06-30');

        foreach (ActivityNature::cases() as $nature) {
            self::assertTrue($table->microCeiling($nature, $on)->isPositive());
            self::assertTrue($table->vatFranchiseBase($nature, $on)->isPositive());
            self::assertTrue($table->vatFranchiseTolerance($nature, $on)->isPositive());
            self::assertTrue($table->socialRate($nature, $on, PensionFund::Ssi)->isPositive());
            self::assertTrue($table->incomeTaxRate($nature, $on)->isPositive());
        }
    }

    /**
     * The tolerance threshold must sit above the base one, or the "you are now
     * liable for VAT" alert would fire before the "you are approaching it" one.
     */
    public function testToleranceThresholdIsAboveTheBaseThreshold(): void
    {
        $table = $this->shippedTable();

        $on = new DateTimeImmutable('2026-06-30');

        foreach (ActivityNature::cases() as $nature) {
            self::assertTrue(
                $table->vatFranchiseTolerance($nature, $on)
                    ->isGreaterThan($table->vatFranchiseBase($nature, $on)),
                $nature->value,
            );
        }
    }

    /**
     * Every figure in the shipped table has now been checked against an official
     * source: the BOFiP barème BOI-BAREME-000036 for the thresholds, décret
     * n° 2024-484 du 30 mai 2024 for the contribution rates. The flags were
     * flipped, and so was this test — which the version that asserted the
     * opposite asked for in as many words.
     *
     * It earns its keep the other way round now: it fails if an entry is added
     * without being checked, which is how the table would quietly go back to
     * shipping guesses under a flag that says otherwise.
     */
    public function testEveryShippedEntryIsMarkedVerified(): void
    {
        $table = $this->shippedTable();

        foreach (['2024-03-31', '2024-12-31', '2025-12-31', '2026-06-30', '2026-12-31'] as $date) {
            self::assertTrue(
                $table->isVerified(new DateTimeImmutable($date)),
                sprintf('The rates in force on %s are not marked verified.', $date),
            );
        }
    }

    public function testResolvesTheEntryInForceOnTheDate(): void
    {
        $table = $this->tableWith([
            'thresholds' => [
                $this->thresholdEntry('2024-01-01', 1_000_000),
                $this->thresholdEntry('2026-01-01', 2_000_000),
            ],
            'contributions' => [
                $this->contributionEntry('2024-01-01', '20'),
                $this->contributionEntry('2026-01-01', '25'),
            ],
        ]);

        $nature = ActivityNature::ServicesBnc;

        // Last day before the new set takes effect.
        self::assertSame(
            '1000000',
            (string) $table->microCeiling($nature, new DateTimeImmutable('2025-12-31')),
        );
        // The day it takes effect.
        self::assertSame(
            '2000000',
            (string) $table->microCeiling($nature, new DateTimeImmutable('2026-01-01')),
        );
        self::assertSame(
            '20',
            (string) $table->socialRate($nature, new DateTimeImmutable('2025-12-31'), PensionFund::Ssi),
        );
        self::assertSame(
            '25',
            (string) $table->socialRate($nature, new DateTimeImmutable('2026-06-30'), PensionFund::Ssi),
        );
    }

    /**
     * Entries are resolved in date order regardless of how they were written
     * down — a rate correction appended to the end of the file must not win
     * over a later one.
     */
    public function testEntriesAreOrderedByEffectiveDateNotFileOrder(): void
    {
        $table = $this->tableWith([
            'thresholds' => [
                $this->thresholdEntry('2026-01-01', 2_000_000),
                $this->thresholdEntry('2024-01-01', 1_000_000),
            ],
            'contributions' => [$this->contributionEntry('2024-01-01', '20')],
        ]);

        self::assertSame(
            '1000000',
            (string) $table->microCeiling(ActivityNature::ServicesBnc, new DateTimeImmutable('2025-06-30')),
        );
    }

    /**
     * Backfilling payments from before the earliest recorded rules should not
     * be a hard error — the oldest known set is the closest thing to right.
     */
    public function testFallsBackToTheOldestEntryForAnEarlierDate(): void
    {
        $table = $this->tableWith([
            'thresholds' => [$this->thresholdEntry('2024-01-01', 1_000_000)],
            'contributions' => [$this->contributionEntry('2024-01-01', '20')],
        ]);

        self::assertSame(
            '1000000',
            (string) $table->microCeiling(ActivityNature::ServicesBnc, new DateTimeImmutable('2019-05-01')),
        );
    }

    public function testCipavAndSsiDifferOnBncTurnover(): void
    {
        $table = $this->shippedTable();

        $on = new DateTimeImmutable('2026-06-30');

        self::assertFalse(
            $table->socialRate(ActivityNature::ServicesBnc, $on, PensionFund::Ssi)
                ->isEqualTo($table->socialRate(ActivityNature::ServicesBnc, $on, PensionFund::Cipav)),
        );

        // The pension fund only ever changes the BNC rate.
        self::assertTrue(
            $table->socialRate(ActivityNature::SaleOfGoods, $on, PensionFund::Ssi)
                ->isEqualTo($table->socialRate(ActivityNature::SaleOfGoods, $on, PensionFund::Cipav)),
        );
    }

    public function testUnknownSectionThrows(): void
    {
        $table = $this->tableWith(['contributions' => [$this->contributionEntry('2024-01-01', '20')]]);

        $this->expectException(RuntimeException::class);

        $table->microCeiling(ActivityNature::ServicesBnc, new DateTimeImmutable('2026-01-01'));
    }

    /**
     * The table the application actually ships and reads in production.
     */
    private function shippedTable(): FrenchRateTable
    {
        return new FrenchRateTable(dirname(__DIR__, 3) . '/Resources/config/fr_micro_rates.php');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function tableWith(array $data): FrenchRateTable
    {
        $file = tempnam(sys_get_temp_dir(), 'rates') . '.php';
        $this->tempFiles[] = $file;

        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');

        return new FrenchRateTable($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholdEntry(string $from, int $ceiling): array
    {
        return [
            'effective_from' => $from,
            'verified' => false,
            'micro_ceiling' => [
                'sale_of_goods' => $ceiling,
                'services_bic' => $ceiling,
                'services_bnc' => $ceiling,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contributionEntry(string $from, string $rate): array
    {
        return [
            'effective_from' => $from,
            'verified' => false,
            'social' => [
                'sale_of_goods' => $rate,
                'services_bic' => $rate,
                'services_bnc_ssi' => $rate,
                'services_bnc_cipav' => $rate,
            ],
        ];
    }
}
