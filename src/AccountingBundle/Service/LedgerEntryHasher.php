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

namespace Augias\AccountingBundle\Service;

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\SettlementMethod;
use function array_map;
use function hash;
use function implode;

/**
 * Computes the hash that chains one sealed ledger entry to the one before it.
 *
 * Sealing is what makes the books worth anything as evidence: change a figure
 * in a closed period and every hash after it stops matching, which
 * {@see LedgerChainVerifier} can demonstrate at any time.
 *
 * The canonical form below is the contract. It is deliberately explicit rather
 * than derived from the entity's properties by reflection: adding a column must
 * not silently change what a hash covers, or every book already sealed would
 * fail to verify after an upgrade. A field that has to enter the hash later
 * gets appended, and the change is then a visible one.
 */
final readonly class LedgerEntryHasher
{
    private const string SEPARATOR = '|';

    private const string SHARE_SEPARATOR = ';';

    private const string FIELD_SEPARATOR = ':';

    /**
     * The hash of an entry, given the hash of the entry before it in its book.
     * The first entry of a book chains onto an empty string.
     */
    public function hash(LedgerEntry $entry, ?string $previousHash): string
    {
        return hash('sha256', $this->canonicalForm($entry, $previousHash));
    }

    /**
     * Everything the hash commits to, in a fixed order.
     *
     * The sequence number is in it, so entries cannot be reordered within a
     * book; the previous hash is in it, so nothing can be inserted between two
     * of them.
     */
    public function canonicalForm(LedgerEntry $entry, ?string $previousHash): string
    {
        $nature = $entry->getActivityNature();
        $settlement = $entry->getSettlementMethod();

        $fields = [
            $previousHash ?? '',
            (string) $entry->getSequenceNumber(),
            $entry->getBook()->value,
            $entry->getEntryDate()->format('Y-m-d'),
            $entry->getLabel(),
            $entry->getCounterpartyName(),
            $entry->getDocumentReference() ?? '',
            (string) $entry->getAmount(),
            $entry->getCurrencyCode(),
            $nature instanceof ActivityNature ? $nature->value : '',
            $settlement instanceof SettlementMethod ? $settlement->value : '',
            $entry->getSource()->value,
            $entry->getSourceId()?->toRfc4122() ?? '',
        ];

        // Appended, and only when there is tax to commit to. An entry without
        // it produces exactly the string it produced before these fields
        // existed, so every book sealed before the upgrade still verifies —
        // which a trailing empty separator would have broken for all of them.
        //
        // The tax has to be in the hash once it is there: a VAT return is filed
        // from these figures, and a sealed entry whose tax could be altered
        // without breaking the chain would make the seal worth less than it
        // claims.
        if ($entry->hasTax()) {
            $fields[] = (string) $entry->getNetAmount();
            $fields[] = (string) $entry->getTaxAmount();
            $fields[] = $this->breakdown($entry);
        }

        return implode(self::SEPARATOR, $fields);
    }

    /**
     * The per-rate split, flattened into one field.
     *
     * Spelled out here rather than json_encode()d: the hash must not change
     * because PHP changed how it escapes a slash, and the order the shares are
     * written in is the order the splitter produced them, which is the
     * document's own.
     */
    private function breakdown(LedgerEntry $entry): string
    {
        return implode(self::SHARE_SEPARATOR, array_map(
            static function (array $share): string {
                $fields = [
                    $share['rate'],
                    $share['category'],
                    $share['base'],
                    $share['tax'],
                ];

                // When the tax fell due decides which return declares it, so
                // it is committed to — appended, and only when present, for
                // the same reason as the tax fields above: every share sealed
                // before it existed still hashes to what it did.
                if (isset($share['due'])) {
                    $fields[] = $share['due'];
                }

                return implode(self::FIELD_SEPARATOR, $fields);
            },
            $entry->getTaxBreakdown() ?? [],
        ));
    }
}
