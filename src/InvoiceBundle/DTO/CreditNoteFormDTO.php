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

namespace Augias\InvoiceBundle\DTO;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Entity\Discount;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditReason;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form data for a credit note.
 *
 * Deliberately narrower than {@see InvoiceFormDTO}: there is no inline
 * client creation here. A credit note answers to something that already
 * happened, so the client always exists — and usually the invoice does too.
 */
final class CreditNoteFormDTO
{
    #[Assert\NotBlank]
    public ?Client $client = null;

    /**
     * Null for a credit that answers to no single invoice — an end-of-year
     * rebate, or a gesture.
     */
    public ?Invoice $creditedInvoice = null;

    #[Assert\NotNull]
    public ?CreditReason $reason = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $creditNoteId = '';

    #[Assert\NotNull]
    public ?DateTimeImmutable $creditNoteDate = null;

    public ?Discount $discount = null;

    public ?string $terms = null;

    public ?string $notes = null;

    public ?string $total = '0';

    public ?string $baseTotal = '0';

    public ?string $tax = '0';

    /**
     * @var Collection<int, CreditNoteLine>
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1)]
    public Collection $lines;

    /**
     * @var Collection<int, Contact>
     */
    #[Assert\Count(min: 1)]
    public Collection $users;

    public function __construct()
    {
        $this->discount = new Discount();
        $this->lines = new ArrayCollection();
        $this->users = new ArrayCollection();
    }
}
