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

namespace Augias\InvoiceBundle\Model;

/**
 * A credit note moves in one direction only.
 *
 * There is no cancel and no edit here either, for the same reason an issued
 * invoice has none: once the document is in the client's hands it is fixed. A
 * credit note raised in error is itself corrected by an invoice.
 */
final class CreditNoteGraph
{
    /** Numbered and handed over. The document stops being editable here. */
    public const string TRANSITION_ISSUE = 'issue';

    /** Used up, whether refunded or set against later invoices. */
    public const string TRANSITION_SETTLE = 'settle';
}
