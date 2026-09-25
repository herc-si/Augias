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

namespace Augias\AccountingBundle\Tests\Entity;

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\LedgerBook;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function array_column;

/**
 * Which part of an entry's tax a return collects on the entry's date.
 */
#[CoversClass(LedgerEntry::class)]
final class LedgerEntryCollectedTaxTest extends TestCase
{
    public function testAPaymentCollectsAllButTheGoodsAlreadyDeclaredOnIssue(): void
    {
        $entry = $this->entry(LedgerBook::Revenue, 180_000)->setTax(BigInteger::of(150_000), BigInteger::of(30_000), [
            ['rate' => '20.0000', 'category' => 'Standard', 'base' => '100000', 'tax' => '20000', 'due' => 'issue'],
            ['rate' => '20.0000', 'category' => 'Standard', 'base' => '50000', 'tax' => '10000'],
        ]);

        self::assertSame('10000', (string) $entry->collectedTax());
        self::assertSame(['50000'], array_column($entry->collectedShares(), 'base'));
    }

    public function testTheSalesJournalCollectsEverythingItRecords(): void
    {
        $entry = $this->entry(LedgerBook::Sales, 120_000)->setTax(BigInteger::of(100_000), BigInteger::of(20_000), [
            ['rate' => '20.0000', 'category' => 'Standard', 'base' => '100000', 'tax' => '20000', 'due' => 'issue'],
        ]);

        self::assertSame('20000', (string) $entry->collectedTax());
        self::assertCount(1, $entry->collectedShares());
    }

    public function testAPurchaseCollectsNothing(): void
    {
        $entry = $this->entry(LedgerBook::Purchase, 12_000)->setTax(BigInteger::of(10_000), BigInteger::of(2_000), []);

        self::assertNull($entry->collectedTax());
        self::assertSame([], $entry->collectedShares());
    }

    /**
     * Refunds used to be written with a negative tax but the positive shares
     * of the payment they reversed. Read as they stand, those shares added a
     * refund's tax to the return instead of taking it off.
     */
    public function testARefundWrittenWithUnsignedSharesIsReadAsTheRefundItIs(): void
    {
        $entry = $this->entry(LedgerBook::Revenue, -60_000)->setTax(BigInteger::of(-50_000), BigInteger::of(-10_000), [
            ['rate' => '20.0000', 'category' => 'Standard', 'base' => '50000', 'tax' => '10000'],
        ]);

        self::assertSame(
            [['rate' => '20.0000', 'category' => 'Standard', 'base' => '-50000', 'tax' => '-10000']],
            $entry->collectedShares(),
        );
    }

    private function entry(LedgerBook $book, int $amount): LedgerEntry
    {
        return new LedgerEntry()->setBook($book)->setAmount(BigInteger::of($amount));
    }
}
