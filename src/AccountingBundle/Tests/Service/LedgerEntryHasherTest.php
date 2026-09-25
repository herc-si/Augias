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
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Service\LedgerEntryHasher;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function strlen;

#[CoversClass(LedgerEntryHasher::class)]
final class LedgerEntryHasherTest extends TestCase
{
    public function testTheSameEntryAlwaysHashesTheSame(): void
    {
        $hasher = new LedgerEntryHasher();

        self::assertSame(
            $hasher->hash($this->entry(), null),
            $hasher->hash($this->entry(), null),
        );
    }

    public function testTheHashIsASha256(): void
    {
        self::assertSame(64, strlen(new LedgerEntryHasher()->hash($this->entry(), null)));
    }

    /**
     * The point of the chain: the same entry sealed behind a different one
     * hashes differently, so entries cannot be reordered or lifted from one
     * book into another.
     */
    public function testTheHashDependsOnTheEntryBefore(): void
    {
        $hasher = new LedgerEntryHasher();

        self::assertNotSame(
            $hasher->hash($this->entry(), null),
            $hasher->hash($this->entry(), 'aaaa'),
        );
    }

    public function testChangingAnyRecordedFieldChangesTheHash(): void
    {
        $hasher = new LedgerEntryHasher();
        $original = $hasher->hash($this->entry(), null);

        $mutations = [
            'sequence number' => static fn (LedgerEntry $e): LedgerEntry => $e->setSequenceNumber(2),
            'date' => static fn (LedgerEntry $e): LedgerEntry => $e->setEntryDate(new DateTimeImmutable('2026-02-11')),
            'label' => static fn (LedgerEntry $e): LedgerEntry => $e->setLabel('Something else'),
            'counterparty' => static fn (LedgerEntry $e): LedgerEntry => $e->setCounterpartyName('Someone else'),
            'reference' => static fn (LedgerEntry $e): LedgerEntry => $e->setDocumentReference('INV-002'),
            'amount' => static fn (LedgerEntry $e): LedgerEntry => $e->setAmount(BigInteger::of(120001)),
            'currency' => static fn (LedgerEntry $e): LedgerEntry => $e->setCurrencyCode('USD'),
            'activity nature' => static fn (LedgerEntry $e): LedgerEntry => $e->setActivityNature(ActivityNature::SaleOfGoods),
            'settlement method' => static fn (LedgerEntry $e): LedgerEntry => $e->setSettlementMethod(SettlementMethod::Cash),
        ];

        foreach ($mutations as $field => $mutate) {
            self::assertNotSame(
                $original,
                $hasher->hash($mutate($this->entry()), null),
                sprintf('Changing the %s must change the hash.', $field),
            );
        }
    }

    /**
     * When a share's tax fell due decides which return declares it, so it is
     * sealed with the rest.
     */
    public function testTheDueDateOfAShareIsCommittedTo(): void
    {
        $hasher = new LedgerEntryHasher();
        $share = ['rate' => '20.0000', 'category' => 'Standard', 'base' => '100000', 'tax' => '20000'];

        self::assertNotSame(
            $hasher->hash($this->entry()->setTax(BigInteger::of(100000), BigInteger::of(20000), [$share]), null),
            $hasher->hash($this->entry()->setTax(BigInteger::of(100000), BigInteger::of(20000), [$share + ['due' => 'issue']]), null),
        );
    }

    /**
     * A share without a due date — every share sealed before there was one —
     * reads exactly as it did then, so books already sealed still verify.
     */
    public function testAShareWithoutADueDateHashesAsItAlwaysDid(): void
    {
        $entry = $this->entry()->setTax(BigInteger::of(100000), BigInteger::of(20000), [
            ['rate' => '20.0000', 'category' => 'Standard', 'base' => '100000', 'tax' => '20000'],
        ]);

        self::assertStringEndsWith('|100000|20000|20.0000:Standard:100000:20000', new LedgerEntryHasher()->canonicalForm($entry, null));
    }

    private function entry(): LedgerEntry
    {
        return new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setSequenceNumber(1)
            ->setEntryDate(new DateTimeImmutable('2026-02-10'))
            ->setLabel('Invoice payment')
            ->setCounterpartyName('Johnston PLC')
            ->setDocumentReference('INV-001')
            ->setAmount(BigInteger::of(120000))
            ->setCurrencyCode('EUR')
            ->setActivityNature(ActivityNature::ServicesBnc)
            ->setSettlementMethod(SettlementMethod::BankTransfer);
    }
}
