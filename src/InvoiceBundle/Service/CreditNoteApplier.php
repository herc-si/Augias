<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\InvoiceBundle\Service;

use Augias\ClientBundle\Entity\Client;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Repository\CreditNoteRepository;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Sets the client's open credit notes against an invoice, oldest first, for at
 * most what the invoice still owes.
 *
 * Each use is an allocation of the credit note itself, so the credit note
 * records which invoice consumed it and cannot also be refunded. Paying with
 * the "Credit" method took the money off the client's credit but left the
 * credit note open, to be refunded a second time.
 */
final readonly class CreditNoteApplier
{
    public function __construct(
        private CreditNoteRepository $creditNotes,
        private CreditNoteAllocator $allocator,
        private InvoiceSettlement $settlement,
    ) {
    }

    /**
     * What the client's issued credit notes still have to give.
     *
     * @throws MathException
     */
    public function available(Client $client): BigDecimal
    {
        $available = BigDecimal::zero();

        foreach ($this->creditNotes->issuedForClient($client) as $creditNote) {
            $available = $available->plus($this->allocator->remaining($creditNote));
        }

        return $available;
    }

    /**
     * @return BigDecimal what was set against the invoice
     *
     * @throws MathException
     * @throws AllocationException
     */
    public function applyTo(Invoice $invoice): BigDecimal
    {
        $client = $invoice->getClient();

        if (! $client instanceof Client) {
            return BigDecimal::zero();
        }

        $applied = BigDecimal::zero();

        foreach ($this->creditNotes->issuedForClient($client) as $creditNote) {
            $owed = $this->settlement->balance($invoice);

            if (! $owed->isPositive()) {
                break;
            }

            $amount = BigDecimal::min($owed, $this->allocator->remaining($creditNote)->toBigDecimal());

            if (! $amount->isPositive()) {
                continue;
            }

            $this->allocator->allocate($creditNote, AllocationKind::Offset, $amount, $invoice);
            $applied = $applied->plus($amount);
        }

        return $applied;
    }
}
