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

namespace Augias\InvoiceBundle\Twig\Extension;

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The number of an invoice or a credit note as a page or a PDF shows it: the
 * number, or "Draft" until it has one. Documents are numbered when finalised,
 * so a draft's page, preview or PDF has no number to print (08/10/2026).
 *
 * The prefix ("#") goes with the number only: "#Draft" reads as a number.
 */
final readonly class DocumentNumberExtension
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[AsTwigFunction(name: 'invoice_number')]
    public function invoiceNumber(?Invoice $invoice, string $prefix = ''): string
    {
        if (! $invoice instanceof Invoice || ! $invoice->isNumbered()) {
            return $this->translator->trans('invoice.number.draft');
        }

        return $prefix . $invoice->getInvoiceId();
    }

    #[AsTwigFunction(name: 'credit_note_number')]
    public function creditNoteNumber(?CreditNote $creditNote, string $prefix = ''): string
    {
        if (! $creditNote instanceof CreditNote || ! $creditNote->isNumbered()) {
            return $this->translator->trans('credit_note.number.draft');
        }

        return $prefix . $creditNote->getCreditNoteId();
    }
}
