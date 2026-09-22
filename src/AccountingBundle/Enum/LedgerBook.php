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

    case Purchase = 'purchase';

    public function getLabel(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue Book',
            self::Purchase => 'Purchase Register',
        };
    }

    public function translationKey(): string
    {
        return match ($this) {
            self::Revenue => 'accounting.book.revenue',
            self::Purchase => 'accounting.book.purchase',
        };
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
            self::Purchase => 'accounting.entry.add_purchase',
        };
    }
}
