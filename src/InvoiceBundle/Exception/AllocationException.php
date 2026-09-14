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

namespace Augias\InvoiceBundle\Exception;

use Augias\InvoiceBundle\Entity\CreditNote;
use Brick\Math\BigNumber;
use DomainException;
use function sprintf;

final class AllocationException extends DomainException
{
    public static function notIssued(CreditNote $creditNote): self
    {
        return new self(sprintf(
            'Credit note %s has not been issued, so nothing is owed on it yet.',
            $creditNote->getCreditNoteId(),
        ));
    }

    public static function notPositive(): self
    {
        return new self('An allocation has to be for a positive amount.');
    }

    public static function exceedsRemaining(CreditNote $creditNote, BigNumber $remaining): self
    {
        return new self(sprintf(
            'Credit note %s has only %s left to allocate.',
            $creditNote->getCreditNoteId(),
            (string) $remaining,
        ));
    }

    public static function offsetNeedsAnInvoice(): self
    {
        return new self('Setting a credit note against an invoice needs the invoice.');
    }

    public static function refundTakesNoInvoice(): self
    {
        return new self('A refund is money leaving, not an offset against an invoice.');
    }

    public static function wrongClient(CreditNote $creditNote): self
    {
        return new self(sprintf(
            'Credit note %s can only be set against invoices of the client it was raised for.',
            $creditNote->getCreditNoteId(),
        ));
    }
}
