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

namespace Augias\AccountingBundle\Bank\Parser;

use Augias\AccountingBundle\Bank\ParsedTransaction;
use Augias\AccountingBundle\Bank\UnreadableStatement;
use Brick\Math\BigDecimal;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\String\UnicodeString;
use function array_map;
use function fgetcsv;
use function fopen;
use function fwrite;
use function in_array;
use function rewind;
use function strtok;
use function substr_count;
use function trim;

/**
 * CSV — the format every bank offers and no two write alike.
 *
 * The separator is guessed from the first line, the columns from their
 * headings (French or English, accents and case aside), and the amount read
 * either from one signed column or from a debit and a credit column. The
 * lines above the heading, where some banks put the account's name and
 * balance, are skipped.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\ParserTest
 */
#[AsTaggedItem(priority: 10)]
final class CsvParser implements StatementParser
{
    private const array DATE = ['date operation', 'date de l operation', 'date', 'date comptable', 'date de comptabilisation', 'booking date', 'transaction date', 'date valeur', 'date de valeur', 'value date'];

    private const array LABEL = ['libelle', 'libelle operation', 'libelle de l operation', 'description', 'label', 'intitule', 'detail', 'details', 'nature de l operation', 'motif'];

    private const array AMOUNT = ['montant', 'amount', 'montant eur', 'montant en euros', 'montant(eur)', 'valeur'];

    private const array DEBIT = ['debit', 'debit eur', 'debit euros', 'montant debit', 'sortie'];

    private const array CREDIT = ['credit', 'credit eur', 'credit euros', 'montant credit', 'entree'];

    public function name(): string
    {
        return 'CSV';
    }

    public function supports(string $content): bool
    {
        return true;
    }

    public function parse(string $content): array
    {
        $rows = $this->rows($content);
        $columns = null;
        $transactions = [];

        foreach ($rows as $row) {
            // Until the heading is found, rows are the bank's preamble.
            if (null === $columns) {
                $columns = $this->columns($row);

                continue;
            }

            $date = trim($row[$columns['date']] ?? '');

            // A total at the foot, a blank line: not an operation.
            if ('' === $date) {
                continue;
            }

            $transactions[] = new ParsedTransaction(
                date: StatementText::date($date),
                amount: $this->amount($row, $columns),
                label: trim($row[$columns['label']] ?? ''),
            );
        }

        if (null === $columns) {
            throw new UnreadableStatement('csv_columns');
        }

        return $transactions;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $content): array
    {
        $firstLine = strtok($content, "\n") ?: '';
        $separator = ';';
        $best = 0;

        foreach ([';', ',', "\t", '|'] as $candidate) {
            if (($count = substr_count($firstLine, $candidate)) > $best) {
                [$separator, $best] = [$candidate, $count];
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];

        while (false !== ($row = fgetcsv($stream, null, $separator, '"', ''))) {
            if ([null] !== $row) {
                $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $row
     *
     * @return array{date: int, label: int, amount?: int, debit?: int, credit?: int}|null the row's columns, when it is the heading
     */
    private function columns(array $row): ?array
    {
        $found = [];

        foreach ($row as $index => $heading) {
            $key = (string) new UnicodeString($heading)->ascii()->lower()->replaceMatches('/[^a-z()]+/', ' ')->trim();

            foreach (['date' => self::DATE, 'label' => self::LABEL, 'amount' => self::AMOUNT, 'debit' => self::DEBIT, 'credit' => self::CREDIT] as $role => $names) {
                if (! isset($found[$role]) && in_array($key, $names, true)) {
                    $found[$role] = $index;
                }
            }
        }

        if (! isset($found['date'], $found['label']) || (! isset($found['amount']) && ! isset($found['debit'], $found['credit']))) {
            return null;
        }

        return $found;
    }

    /**
     * @param list<string> $row
     * @param array{date: int, label: int, amount?: int, debit?: int, credit?: int} $columns
     */
    private function amount(array $row, array $columns): BigDecimal
    {
        if (isset($columns['amount']) && '' !== trim($row[$columns['amount']] ?? '')) {
            return StatementText::amount($row[$columns['amount']]);
        }

        $debit = trim($row[$columns['debit'] ?? -1] ?? '');
        $credit = trim($row[$columns['credit'] ?? -1] ?? '');

        if ('' !== $credit) {
            return StatementText::amount($credit)->abs();
        }

        if ('' !== $debit) {
            // Written positive by most banks, negative by some: out is out.
            return StatementText::amount($debit)->abs()->negated();
        }

        throw new UnreadableStatement('invalid_amount', ['%value%' => '']);
    }
}
