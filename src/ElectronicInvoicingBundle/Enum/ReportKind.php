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

namespace Augias\ElectronicInvoicingBundle\Enum;

/**
 * What a company reports for the tax administration beyond the invoices
 * themselves: sales to private individuals and the money received for them,
 * and the money received on invoices it sent electronically.
 */
enum ReportKind: string
{
    /** The sale itself: amounts and VAT, by category. */
    case Transaction = 'transaction';

    /** Money received for services whose VAT falls due on payment. */
    case Payment = 'payment';

    /**
     * Money received on an invoice sent electronically, for services whose
     * VAT falls due on payment: the "paid" status (fr:212) on that invoice.
     */
    case PaymentReceived = 'payment_received';

    /**
     * A purchase received electronically, paid: the "payment sent" status
     * (fr:211) on that invoice, so the supplier sees it. Optional in the
     * reform, sent once the invoice was accepted.
     */
    case PaymentSent = 'payment_sent';

    /** A credit note to a private individual: the sale, going the other way. */
    case CreditNote = 'credit_note';

    /** A credit note to a private individual, paid back. */
    case Refund = 'refund';
}
