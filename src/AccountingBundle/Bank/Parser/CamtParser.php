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

use const LIBXML_NONET;
use Augias\AccountingBundle\Bank\ParsedTransaction;
use Augias\AccountingBundle\Bank\UnreadableStatement;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use function implode;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function str_contains;
use function substr;
use function trim;

/**
 * CAMT.053 — the ISO 20022 end-of-day statement every European bank offers.
 *
 * Read by local name, whatever the version's namespace: the elements this
 * needs have not moved between camt.053.001.02 and .08, except the party's
 * name, which later versions nest one level deeper.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\ParserTest
 */
#[AsTaggedItem(priority: 30)]
final class CamtParser implements StatementParser
{
    public function name(): string
    {
        return 'CAMT.053';
    }

    public function supports(string $content): bool
    {
        return str_contains($content, 'BkToCstmrStmt');
    }

    public function parse(string $content): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            // No network, no entity expansion: a statement is data, not a program.
            if (! $document->loadXML($content, LIBXML_NONET)) {
                throw new UnreadableStatement('invalid_xml');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $transactions = [];

        foreach ($xpath->query('//*[local-name()="Ntry"]') ?: [] as $entry) {
            if (! $entry instanceof DOMElement) {
                continue;
            }

            $amountNode = $this->first($xpath, './*[local-name()="Amt"]', $entry);
            $date = $this->text($xpath, './*[local-name()="BookgDt"]/*', $entry) ?? $this->text($xpath, './*[local-name()="ValDt"]/*', $entry);

            if (! $amountNode instanceof DOMElement || null === $date) {
                throw new UnreadableStatement('missing_field');
            }

            try {
                $amount = BigDecimal::of(trim($amountNode->textContent));
            } catch (MathException) {
                throw new UnreadableStatement('invalid_amount', ['%value%' => $amountNode->textContent]);
            }

            $credit = 'CRDT' === $this->text($xpath, './*[local-name()="CdtDbtInd"]', $entry);
            $party = $credit ? 'Dbtr' : 'Cdtr';

            $label = $this->texts($xpath, './/*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $entry)
                ?? $this->text($xpath, './*[local-name()="AddtlNtryInf"]', $entry)
                ?? '';

            $transactions[] = new ParsedTransaction(
                date: new DateTimeImmutable(substr($date, 0, 10)),
                amount: $credit ? $amount : $amount->negated(),
                label: $label,
                counterparty: $this->text($xpath, './/*[local-name()="RltdPties"]/*[local-name()="' . $party . '"]//*[local-name()="Nm"]', $entry),
                reference: $this->text($xpath, './*[local-name()="AcctSvcrRef"]', $entry) ?? $this->text($xpath, './*[local-name()="NtryRef"]', $entry),
                currency: '' !== $amountNode->getAttribute('Ccy') ? $amountNode->getAttribute('Ccy') : null,
            );
        }

        return $transactions;
    }

    private function first(DOMXPath $xpath, string $query, DOMNode $context): ?DOMNode
    {
        $nodes = $xpath->query($query, $context);

        return false === $nodes ? null : $nodes->item(0);
    }

    private function text(DOMXPath $xpath, string $query, DOMNode $context): ?string
    {
        $value = trim((string) $this->first($xpath, $query, $context)?->textContent);

        return '' === $value ? null : $value;
    }

    private function texts(DOMXPath $xpath, string $query, DOMNode $context): ?string
    {
        $parts = [];

        foreach ($xpath->query($query, $context) ?: [] as $node) {
            $value = trim($node->textContent);

            if ('' !== $value) {
                $parts[] = $value;
            }
        }

        return [] === $parts ? null : implode(' ', $parts);
    }
}
