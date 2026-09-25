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

namespace Augias\AccountingBundle\Enum;

/**
 * Which of the two statutory books an entry belongs to. Both are stored in the
 * same table and discriminated by this value, because they hold exactly the
 * same columns and are always read the same way — only the direction of the
 * money and a couple of labels differ.
 *
 * A French micro-entrepreneur must keep {@see self::Revenue} (livre des
 * recettes) unconditionally, and {@see self::Purchase} (registre des achats)
 * only for resale and accommodation activities. Which books a company actually
 * has to keep is decided by its regime, not here — see
 * {@see \Augias\AccountingBundle\Regime\RegimeInterface::books()}.
 */
enum LedgerBook: string
{
    case Revenue = 'revenue';

    /**
     * The VAT that fell due when a document was issued rather than when it
     * was paid: the goods on every invoice — and its services too, under the
     * option for VAT on debits — taken back by every credit note.
     *
     * Kept apart from the revenue book because it records a different event.
     * The revenue book is what came in, which is what a micro-entrepreneur's
     * turnover is and what VAT on services follows. VAT on goods falls due on
     * delivery (CGI art. 269, 2-a), paid or not, and a return that waited for
     * the money declared it late. Nothing here is turnover: the sale reaches
     * the revenue book when it is paid, as it always has.
     *
     * Written by the application alone — an entry here mirrors an issued
     * document, and a hand-written one would have no document to mirror.
     */
    case Sales = 'sales';

    case Purchase = 'purchase';

    /**
     * The mirror of the sales journal on the purchase side: the VAT that
     * became deductible when a supplier's bill was received rather than when
     * it was paid — goods, or services from a supplier on debits. The right
     * to deduct arises when the tax falls due at the supplier (CGI art. 271,
     * I-2), and for those that is the bill's date, not the payment's.
     *
     * Not the purchase register: nothing here was paid, and the purchase
     * register is what was. Written by the application alone.
     */
    case Bills = 'bills';

    /**
     * Expenses, which no regime requires and every company may keep.
     *
     * Not a statutory book: a micro-entrepreneur deducts nothing, so the law
     * asks for no record of what they spent. They still have receipts to
     * file — proof of a purchase, the VAT on it once they are liable, and the
     * beginnings of the charges a réel regime will deduct. Those went nowhere,
     * and a livre des recettes holding expenses would no longer be a livre des
     * recettes.
     */
    case Expense = 'expense';

    public function getLabel(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue Book',
            self::Sales => 'Sales Journal',
            self::Purchase => 'Purchase Register',
            self::Bills => 'Purchase Journal',
            self::Expense => 'Expenses',
        };
    }

    public function translationKey(): string
    {
        return match ($this) {
            self::Revenue => 'accounting.book.revenue',
            self::Sales => 'accounting.book.sales',
            self::Purchase => 'accounting.book.purchase',
            self::Bills => 'accounting.book.bills',
            self::Expense => 'accounting.book.expense',
        };
    }

    /**
     * Whether the law asks for this book. Only the statutory ones count as
     * turnover, feed a declaration or get sealed with a period.
     */
    public function isStatutory(): bool
    {
        return $this !== self::Expense;
    }

    /**
     * Whether the person can write in this book by hand. The two journals
     * mirror documents one for one, so they cannot.
     */
    public function acceptsManualEntries(): bool
    {
        return $this !== self::Sales && $this !== self::Bills;
    }

    /**
     * What the person is about to write down, in their words rather than an
     * accountant's.
     *
     * "New entry" is the word for whoever keeps books for a living. Someone
     * running a micro-entreprise is recording a payment received or a purchase
     * made, and the button should say so — the book already decides which.
     */
    public function addTranslationKey(): string
    {
        return match ($this) {
            self::Revenue => 'accounting.entry.add_revenue',
            self::Sales => 'accounting.entry.add_revenue',
            self::Purchase, self::Bills => 'accounting.entry.add_purchase',
            self::Expense => 'accounting.entry.add_expense',
        };
    }
}
