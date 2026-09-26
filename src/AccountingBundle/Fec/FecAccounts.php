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

namespace Augias\AccountingBundle\Fec;

use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\SettlementMethod;

/**
 * The accounts of the French chart (PCG) the books' entries are shown on.
 *
 * Augias keeps single-entry books — what came in, what went out. The FEC is
 * double-entry, so each entry is laid out on the few accounts it plainly
 * moves: the bank or the till on one side; the sale, the purchase or the
 * expense, and the VAT, on the other. The notice that goes with the file
 * says so, account by account.
 */
final class FecAccounts
{
    public const array LABELS = [
        '512' => 'Banque',
        '530' => 'Caisse',
        '604' => 'Achats de prestations de services',
        '607' => 'Achats de marchandises',
        '628' => 'Charges diverses',
        '706' => 'Prestations de services',
        '707' => 'Ventes de marchandises',
        '44566' => 'TVA déductible sur autres biens et services',
        '44571' => 'TVA collectée',
    ];

    public static function treasury(?SettlementMethod $method): string
    {
        return SettlementMethod::Cash === $method ? '530' : '512';
    }

    /**
     * The account a receipt, a purchase or an expense is taken to.
     */
    public static function counterpart(LedgerBook $book, ?ActivityNature $nature): string
    {
        return match ($book) {
            LedgerBook::Revenue => ActivityNature::SaleOfGoods === $nature ? '707' : '706',
            LedgerBook::Purchase => null === $nature || ActivityNature::SaleOfGoods === $nature ? '607' : '604',
            default => '628',
        };
    }

    public static function vat(LedgerBook $book): string
    {
        return LedgerBook::Revenue === $book ? '44571' : '44566';
    }

    public static function label(string $account): string
    {
        return self::LABELS[$account] ?? $account;
    }
}
