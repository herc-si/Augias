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

use const PREG_SET_ORDER;
use Augias\AccountingBundle\Bank\ParsedTransaction;
use Augias\AccountingBundle\Bank\UnreadableStatement;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use function html_entity_decode;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function substr;
use function trim;

/**
 * OFX — Quicken's format, still offered by most French banks, in its SGML
 * version 1 (tags left open) as in its XML version 2. Read by pattern rather
 * than by a parser, which is what makes both work.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\ParserTest
 */
#[AsTaggedItem(priority: 20)]
final class OfxParser implements StatementParser
{
    public function name(): string
    {
        return 'OFX';
    }

    public function supports(string $content): bool
    {
        return str_contains($content, '<OFX>') || str_contains($content, 'OFXHEADER');
    }

    public function parse(string $content): array
    {
        $currency = $this->tag('CURDEF', $content);
        $transactions = [];

        preg_match_all('#<STMTTRN>(.*?)(?:</STMTTRN>|(?=<STMTTRN>)|(?=</BANKTRANLIST>))#s', $content, $blocks, PREG_SET_ORDER);

        foreach ($blocks as [, $block]) {
            $posted = $this->tag('DTPOSTED', $block);
            $amount = $this->tag('TRNAMT', $block);

            if (null === $posted || null === $amount) {
                throw new UnreadableStatement('missing_field');
            }

            $name = $this->tag('NAME', $block);
            $memo = $this->tag('MEMO', $block);

            $transactions[] = new ParsedTransaction(
                date: StatementText::date(substr($posted, 0, 8)),
                amount: StatementText::amount($amount),
                label: trim(($name ?? '') . ' ' . ($memo ?? '')),
                counterparty: $name,
                reference: $this->tag('FITID', $block),
                currency: $currency,
            );
        }

        return $transactions;
    }

    private function tag(string $name, string $content): ?string
    {
        if (1 !== preg_match('#<' . $name . '>([^<\r\n]*)#', $content, $match)) {
            return null;
        }

        $value = trim(html_entity_decode($match[1]));

        return '' === $value ? null : $value;
    }
}
