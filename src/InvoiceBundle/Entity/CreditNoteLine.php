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

namespace Augias\InvoiceBundle\Entity;

use Augias\InvoiceBundle\Repository\CreditNoteLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of a credit note, sharing the `invoice_lines` table with invoice and
 * recurring invoice lines through the discriminator — the same arrangement
 * {@see RecurringInvoiceLine} already uses.
 *
 * Sharing the table is what keeps line taxes working: {@see \Augias\TaxBundle\Entity\LineTax}
 * points at {@see Line}, so a credit note line carries its taxes with no second
 * mapping, and the quantity, price and total columns behave identically.
 */
#[ORM\Entity(repositoryClass: CreditNoteLineRepository::class)]
class CreditNoteLine extends Line
{
    #[ORM\ManyToOne(targetEntity: CreditNote::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'credit_note_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?CreditNote $creditNote = null;

    public function getCreditNote(): ?CreditNote
    {
        return $this->creditNote;
    }

    public function setCreditNote(?CreditNote $creditNote): self
    {
        $this->creditNote = $creditNote;

        return $this;
    }
}
