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

namespace Augias\InvoiceBundle\Email;

use Augias\InvoiceBundle\Entity\CreditNote;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final class CreditNoteEmail extends TemplatedEmail
{
    public function __construct(
        private readonly CreditNote $creditNote,
    ) {
        parent::__construct();

        $this->htmlTemplate('@AugiasInvoice/Email/credit_note.html.twig');
        $this->context(['creditNote' => $this->creditNote]);
    }

    public function getCreditNote(): CreditNote
    {
        return $this->creditNote;
    }
}
