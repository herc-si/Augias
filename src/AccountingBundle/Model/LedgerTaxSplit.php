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

use Brick\Math\BigInteger;
use function array_map;

/**
 * What a payment splits into, once its tax is separated from it.
 *
 * The invariant this type exists to hold: net plus tax is the amount that
 * actually moved, and the shares add up to the same two figures. Nothing
 * downstream has to re-derive either, which is what keeps a ledger's own
 * totals from disagreeing with the return filed from them.
 */
final readonly class LedgerTaxSplit
{
    /**
     * @param list<TaxShare> $shares
     */
    public function __construct(
        public BigInteger $net,
        public BigInteger $tax,
        public array $shares,
    ) {
    }

    /**
     * The same split, going the other way: net, tax and every share. A split
     * whose shares kept their sign under a negative tax would be declared as
     * tax collected.
     */
    public function negated(): self
    {
        return new self(
            $this->net->negated(),
            $this->tax->negated(),
            array_map(static fn (TaxShare $share): TaxShare => $share->negated(), $this->shares),
        );
    }

    /**
     * The shares on goods alone, marked as due on issue — what a deposit
     * takes back out of the sales journal. Null when there are none.
     */
    public function goodsDueOnIssue(): ?self
    {
        $net = BigInteger::zero();
        $tax = BigInteger::zero();
        $shares = [];

        foreach ($this->shares as $share) {
            if (! $share->goods) {
                continue;
            }

            $shares[] = new TaxShare($share->rate, $share->category, $share->base, $share->tax, true, true);
            $net = $net->plus($share->base);
            $tax = $tax->plus($share->tax);
        }

        return [] === $shares ? null : new self($net, $tax, $shares);
    }

    /**
     * @return list<array{rate: string, category: string, base: string, tax: string, due?: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (TaxShare $share): array => $share->toArray(), $this->shares);
    }
}
