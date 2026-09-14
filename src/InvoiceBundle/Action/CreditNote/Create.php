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

namespace Augias\InvoiceBundle\Action\CreditNote;

use Augias\InvoiceBundle\DTO\CreditNoteFormDTO;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Manager\CreditNoteFormManager;
use Carbon\CarbonImmutable;
use Symfony\Bridge\Twig\Attribute\Template;

/**
 * Opens the form for a new credit note. Saving happens in the live component,
 * the way it does for invoices.
 *
 * Reached from an invoice, the form opens with that invoice's lines mirrored:
 * cancelling in full is the common case, and re-typing what is already on the
 * invoice is how the two documents drift apart.
 */
final readonly class Create
{
    public function __construct(
        private CreditNoteFormManager $formManager,
    ) {
    }

    /**
     * @return array{dto: CreditNoteFormDTO, isEdit: false, creditNote: null}
     */
    #[Template('@AugiasInvoice/CreditNote/create.html.twig')]
    public function __invoke(?Invoice $invoice = null): array
    {
        if ($invoice instanceof Invoice) {
            $dto = $this->formManager->cancellationOf($invoice);
        } else {
            $dto = new CreditNoteFormDTO();
            $dto->creditNoteDate = CarbonImmutable::now();
            $dto->lines->add(new CreditNoteLine());
        }

        return ['dto' => $dto, 'isEdit' => false, 'creditNote' => null];
    }
}
