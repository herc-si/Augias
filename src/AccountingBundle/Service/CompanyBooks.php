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

use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use function in_array;

/**
 * Which books a company has, statutory or not.
 *
 * The regime answers for the statutory ones and nothing else, which is right:
 * it exists to say what the law requires. Expenses are the other kind — no
 * regime asks for them, every company may keep them — and the rule that they
 * are offered alongside lives here rather than in each of the three places
 * that needed it, where it drifted apart the moment one of them was changed.
 *
 * @see \Augias\AccountingBundle\Tests\Service\CompanyBooksTest
 */
final readonly class CompanyBooks
{
    public function __construct(
        private RegimeRegistry $registry,
    ) {
    }

    /**
     * The books the law asks of this company: its regime's, and the two VAT
     * journals once it charges VAT.
     *
     * The sales journal is not the regime's to decide, for the same reason the
     * VAT return is not a regime: a micro-entrepreneur over the franchise
     * threshold charges VAT on goods exactly as a company au réel does, and
     * owes it on the same day.
     *
     * @return list<LedgerBook>
     */
    public function statutory(AccountingProfile $profile): array
    {
        $regime = $this->registry->forProfile($profile);

        if (! $regime instanceof RegimeInterface) {
            return [];
        }

        $books = $regime->books($profile);

        if (! $profile->vatExempt) {
            $books[] = LedgerBook::Sales;

            // Its purchase-side mirror, wherever purchases are kept at all:
            // VAT deducted on a bill's date needs somewhere to be recorded.
            if (in_array(LedgerBook::Purchase, $books, true)) {
                $books[] = LedgerBook::Bills;
            }
        }

        return $books;
    }

    /**
     * Everything the company can open, in the order the menu shows them:
     * its statutory books, then its expenses.
     *
     * Empty until a regime is chosen — a book list before that would be a
     * guess, and the expenses book alone would be an odd place to start.
     *
     * @return list<LedgerBook>
     */
    public function all(AccountingProfile $profile): array
    {
        $statutory = $this->statutory($profile);

        return $statutory === [] ? [] : [...$statutory, LedgerBook::Expense];
    }

    public function keeps(AccountingProfile $profile, ?LedgerBook $book): bool
    {
        return $book instanceof LedgerBook && in_array($book, $this->all($profile), true);
    }
}
