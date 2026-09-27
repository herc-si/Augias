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
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One statement format. Asked in turn whether it recognises a file; the
 * first that does reads it.
 */
#[AutoconfigureTag(self::TAG)]
interface StatementParser
{
    public const string TAG = 'augias.bank_statement_parser';

    public function name(): string;

    public function supports(string $content): bool;

    /**
     * @return list<ParsedTransaction>
     *
     * @throws UnreadableStatement
     */
    public function parse(string $content): array;
}
