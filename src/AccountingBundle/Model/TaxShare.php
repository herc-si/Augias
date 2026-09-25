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

namespace Augias\AccountingBundle\Model;

use Augias\TaxBundle\Enum\TaxCategory;
use Brick\Math\BigInteger;

/**
 * One rate's share of what a payment bore in tax.
 *
 * A VAT return is filled in per rate — a base and a tax for each — so a single
 * figure for the whole payment cannot fill it in. The category rides along
 * because two operations at the same rate do not go in the same box: a
 * zero-rated sale and a reverse-charge sale are both 0%, and are declared
 * separately.
 */
final readonly class TaxShare
{
    /** The value of a stored share's `due` key when its tax fell due on issue. */
    public const string DUE_ON_ISSUE = 'issue';

    public function __construct(
        /** As snapshotted on the document: a percentage, "20.0000". */
        public string $rate,
        public TaxCategory $category,
        /** Minor units, the amount the rate applied to. */
        public BigInteger $base,
        /** Minor units. Zero for a zero-rated or reverse-charge operation. */
        public BigInteger $tax,
        /**
         * Whether this tax fell due when the document was issued — goods —
         * rather than when it was paid. On a payment such a share is recorded,
         * since the money did contain it, but declared from the sales journal.
         */
        public bool $dueOnIssue = false,
    ) {
    }

    /**
     * The same share, going the other way — for money given back.
     */
    public function negated(): self
    {
        return new self($this->rate, $this->category, $this->base->negated(), $this->tax->negated(), $this->dueOnIssue);
    }

    /**
     * The flag is written only when set, so that every share recorded before
     * it existed reads — and hashes — exactly as it did.
     *
     * @return array{rate: string, category: string, base: string, tax: string, due?: string}
     */
    public function toArray(): array
    {
        $share = [
            'rate' => $this->rate,
            'category' => $this->category->value,
            'base' => (string) $this->base,
            'tax' => (string) $this->tax,
        ];

        if ($this->dueOnIssue) {
            $share['due'] = self::DUE_ON_ISSUE;
        }

        return $share;
    }
}
