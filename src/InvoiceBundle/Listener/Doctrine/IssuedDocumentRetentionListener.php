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

namespace Augias\InvoiceBundle\Listener\Doctrine;

use Augias\CoreBundle\Company\CompanyClosure;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Exception\DocumentMustBeKept;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use function in_array;
use function sprintf;

/**
 * Refuses to delete an invoice or a credit note once it has been issued.
 *
 * Every way of deleting one ends here: the invoice list, a client taken away
 * with everything it owns, the API, an assistant. Only the deletion of the
 * whole company, at the end of a closure, gets past. A draft goes freely — it
 * was never handed to anyone. Anything past it, cancelled or archived
 * included, stays: it was issued, and an issued document is kept for as long
 * as the law says, whatever else happens around it.
 *
 * The screens that offer a deletion check first and say why; this is what
 * holds when one of them forgets.
 *
 * @see \Augias\InvoiceBundle\Tests\Listener\Doctrine\IssuedDocumentRetentionListenerTest
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemoveInvoice', entity: Invoice::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemoveCreditNote', entity: CreditNote::class)]
final class IssuedDocumentRetentionListener
{
    public function __construct(
        private readonly ?CompanyClosure $closure = null,
    ) {
    }

    /**
     * The only states an invoice is in before anyone has seen it.
     */
    private const array NEVER_ISSUED = [InvoiceStatus::New, InvoiceStatus::Draft];

    public static function wasIssued(Invoice $invoice): bool
    {
        $status = $invoice->getStatus();

        return null !== $status && ! in_array($status, self::NEVER_ISSUED, true);
    }

    public function preRemoveInvoice(Invoice $invoice): void
    {
        if (self::wasIssued($invoice) && ! $this->closing($invoice->getCompany())) {
            throw new DocumentMustBeKept(sprintf('Invoice %s has been issued and must be kept.', $invoice->getInvoiceId()), 'invoice.delete.issued', ['%number%' => $invoice->getInvoiceId()]);
        }
    }

    public function preRemoveCreditNote(CreditNote $creditNote): void
    {
        if ($creditNote->isIssued() && ! $this->closing($creditNote->getCompany())) {
            throw new DocumentMustBeKept(sprintf('Credit note %s has been issued and must be kept.', $creditNote->getCreditNoteId()), 'credit_note.delete.issued', ['%number%' => $creditNote->getCreditNoteId()]);
        }
    }

    /**
     * The one exception: the company itself is being deleted, at the end of
     * the closure its owner asked for — they were told that keeping the
     * documents from then on was theirs to do.
     */
    private function closing(Company $company): bool
    {
        return $this->closure?->isPurging($company) ?? false;
    }
}
