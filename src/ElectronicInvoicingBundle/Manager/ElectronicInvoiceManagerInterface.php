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

namespace Augias\ElectronicInvoicingBundle\Manager;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use LogicException;

interface ElectronicInvoiceManagerInterface
{
    /**
     * Electronic invoicing only applies to French B2B clients with a SIRET —
     * not every client — so this must be true before showing the manual send
     * button or triggering the automatic send on publish.
     */
    public function isEligible(Invoice | CreditNote $invoice): bool;

    /**
     * @throws LogicException if no active provider is configured, or it is unknown
     */
    public function send(Invoice | CreditNote $invoice): ElectronicInvoiceSubmission;
}
