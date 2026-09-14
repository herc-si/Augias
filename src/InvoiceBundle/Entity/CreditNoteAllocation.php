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

use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Repository\CreditNoteAllocationRepository;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One use of a credit note: money set against an invoice, or money paid back.
 *
 * The client's credit balance already says how much is owed, but a balance is a
 * single number and cannot say where it came from or where it went. This is the
 * journal underneath it — without it there is no way to show which invoice
 * consumed which credit note, which is exactly the question an audit asks.
 *
 * A credit note may be used up in several goes: part set against next month's
 * invoice, the rest refunded.
 */
#[ORM\Table(name: CreditNoteAllocation::TABLE_NAME)]
#[ORM\Index(name: 'idx_allocation_credit_note', columns: ['credit_note_id'])]
#[ORM\Index(name: 'idx_allocation_invoice', columns: ['invoice_id'])]
#[ORM\Entity(repositoryClass: CreditNoteAllocationRepository::class)]
#[ORM\AssociationOverrides([new ORM\AssociationOverride(name: 'company', inversedBy: 'creditNoteAllocations')])]
class CreditNoteAllocation
{
    final public const string TABLE_NAME = 'credit_note_allocations';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: CreditNote::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'credit_note_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CreditNote $creditNote;

    /**
     * Set for an offset, null for a refund. Kept on SET NULL rather than
     * cascading: deleting an invoice must not erase the record of a credit
     * having been used.
     */
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'creditNoteAllocations')]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\Column(name: 'kind', type: Types::STRING, length: 20, enumType: AllocationKind::class)]
    private AllocationKind $kind;

    /**
     * Positive, like every other amount on a credit note. Which way it runs is
     * carried by {@see $kind} and by the document itself.
     */
    #[ORM\Column(name: 'amount', type: BigIntegerType::NAME)]
    private BigNumber $amount;

    #[ORM\Column(name: 'allocated_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $allocatedOn;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->amount = BigInteger::zero();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getCreditNote(): CreditNote
    {
        return $this->creditNote;
    }

    public function setCreditNote(CreditNote $creditNote): self
    {
        $this->creditNote = $creditNote;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getKind(): AllocationKind
    {
        return $this->kind;
    }

    public function setKind(AllocationKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getAmount(): BigNumber
    {
        return $this->amount;
    }

    public function setAmount(BigNumber | int | string $amount): self
    {
        $this->amount = BigNumber::of($amount);

        return $this;
    }

    public function getAllocatedOn(): DateTimeImmutable
    {
        return $this->allocatedOn;
    }

    public function setAllocatedOn(DateTimeImmutable $allocatedOn): self
    {
        $this->allocatedOn = $allocatedOn;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }
}
