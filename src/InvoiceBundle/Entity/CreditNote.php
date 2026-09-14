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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Repository\CreditNoteRepository;
use Augias\TaxBundle\Entity\InvoiceTax;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A credit note — an *avoir* — is how an issued invoice is corrected. The
 * invoice itself is never rewritten: once it has gone to the client it is a
 * fixed document, and every correction is a second document that points back at
 * it.
 *
 * Amounts are stored **positive**, exactly as an invoice stores them. The sign
 * is applied where the document is read — in the books, on the client's
 * balance, on the printed total. Two reasons: every line, tax and discount
 * calculation in the application assumes positive amounts, and EN 16931 wants
 * positive amounts on a credit note too, because it is the document type code
 * (381 rather than 380) that carries the meaning, not the sign.
 *
 * Deliberately not {@see \Augias\CoreBundle\Traits\Entity\Archivable}: a draft
 * is deleted outright, and an issued credit note is a document the books may
 * refer to, so it is never hidden.
 */
#[ORM\Table(name: CreditNote::TABLE_NAME)]
#[ORM\Index(name: 'idx_credit_note_credited', columns: ['credited_invoice_id'])]
#[ORM\Entity(repositoryClass: CreditNoteRepository::class)]
#[ORM\AssociationOverrides([new ORM\AssociationOverride(name: 'company', inversedBy: 'creditNotes')])]
class CreditNote extends BaseInvoice implements Stringable
{
    final public const string TABLE_NAME = 'credit_notes';

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * The number printed on the document, drawn from the credit note's own
     * series — never the invoice series.
     */
    #[ORM\Column(name: 'credit_note_id', type: Types::STRING, length: 255)]
    private string $creditNoteId = '';

    #[ORM\Column(name: 'uuid', type: Types::STRING, length: 36)]
    private string $uuid = '';

    #[ORM\Column(name: 'status', type: Types::STRING, length: 25, enumType: CreditNoteStatus::class)]
    private CreditNoteStatus $status = CreditNoteStatus::Draft;

    #[ORM\Column(name: 'reason', type: Types::STRING, length: 25, nullable: true, enumType: CreditReason::class)]
    #[Assert\NotNull]
    private ?CreditReason $reason = null;

    #[ORM\ManyToOne(targetEntity: Client::class, cascade: ['persist'], inversedBy: 'creditNotes')]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotBlank]
    private Client $client;

    /**
     * Null on purpose for a credit that answers to no single invoice — an
     * end-of-year rebate, or a gesture. The invoice is kept on SET NULL rather
     * than CASCADE: deleting an invoice must never quietly take the correction
     * with it.
     */
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'creditNotes')]
    #[ORM\JoinColumn(name: 'credited_invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $creditedInvoice = null;

    #[ORM\Column(name: 'credit_note_date', type: Types::DATE_IMMUTABLE, nullable: false)]
    #[Assert\Type(type: DateTimeInterface::class)]
    private DateTimeImmutable $creditNoteDate;

    /**
     * When the document was numbered and handed over. From this moment it is
     * fixed, and the client is owed the amount.
     */
    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $issuedAt = null;

    /**
     * @var Collection<int, CreditNoteLine>
     */
    #[ORM\OneToMany(targetEntity: CreditNoteLine::class, mappedBy: 'creditNote', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'credit_note.lines.min')]
    private Collection $lines;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\ManyToMany(targetEntity: Contact::class, inversedBy: 'creditNotes')]
    #[ORM\JoinTable(name: 'credit_note_contact')]
    #[ORM\JoinColumn(name: 'credit_note_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'contact_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Assert\Count(min: 1, minMessage: 'credit_note.users.min')]
    private Collection $users;

    /**
     * Every use of this credit note — set against an invoice, or refunded. A
     * credit note may be used up in several goes.
     *
     * @var Collection<int, CreditNoteAllocation>
     */
    #[ORM\OneToMany(targetEntity: CreditNoteAllocation::class, mappedBy: 'creditNote', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['allocatedOn' => 'ASC'])]
    private Collection $allocations;

    /**
     * @var Collection<int, InvoiceTax>
     */
    #[ORM\OneToMany(targetEntity: InvoiceTax::class, mappedBy: 'creditNote', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $invoiceTaxes;

    public function __construct()
    {
        parent::__construct();

        $this->lines = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->invoiceTaxes = new ArrayCollection();
        $this->allocations = new ArrayCollection();
        $this->creditNoteDate = CarbonImmutable::now();
        $this->setUuid(Uuid::v7());
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    /**
     * @return Collection<int, CreditNoteAllocation>
     */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    public function addAllocation(CreditNoteAllocation $allocation): self
    {
        $this->allocations->add($allocation);
        $allocation->setCreditNote($this);

        return $this;
    }

    public function getCreditNoteId(): string
    {
        return $this->creditNoteId;
    }

    public function setCreditNoteId(string $creditNoteId): self
    {
        $this->creditNoteId = $creditNoteId;

        return $this;
    }

    public function getUuid(): Uuid
    {
        return Uuid::fromString($this->uuid);
    }

    public function setUuid(Uuid $uuid): self
    {
        $this->uuid = $uuid->toString();

        return $this;
    }

    public function getStatus(): CreditNoteStatus
    {
        return $this->status;
    }

    public function setStatus(CreditNoteStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusValue(): string
    {
        return $this->status->value;
    }

    public function setStatusValue(string $status): self
    {
        $this->status = CreditNoteStatus::from($status);

        return $this;
    }

    public function getReason(): ?CreditReason
    {
        return $this->reason;
    }

    public function setReason(CreditReason $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function setClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getCreditedInvoice(): ?Invoice
    {
        return $this->creditedInvoice;
    }

    public function setCreditedInvoice(?Invoice $invoice): self
    {
        $this->creditedInvoice = $invoice;

        return $this;
    }

    public function getCreditNoteDate(): DateTimeImmutable
    {
        return $this->creditNoteDate;
    }

    public function setCreditNoteDate(DateTimeImmutable $creditNoteDate): self
    {
        $this->creditNoteDate = $creditNoteDate;

        return $this;
    }

    public function getIssuedAt(): ?DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    /**
     * A credit note stops being editable the moment it is handed over, which is
     * the whole point of raising one instead of changing the invoice.
     */
    public function isIssued(): bool
    {
        return CreditNoteStatus::Draft !== $this->status;
    }

    /**
     * @return Collection<int, CreditNoteLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(CreditNoteLine $line): self
    {
        $this->lines->add($line);
        $line->setCreditNote($this);

        return $this;
    }

    public function removeLine(CreditNoteLine $line): self
    {
        $this->lines->removeElement($line);
        $line->setCreditNote(null);

        return $this;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(Contact $user): self
    {
        $this->users->add($user);

        return $this;
    }

    public function removeUser(Contact $user): self
    {
        $this->users->removeElement($user);

        return $this;
    }

    /**
     * @return Collection<int, InvoiceTax>
     */
    public function getInvoiceTaxes(): Collection
    {
        return $this->invoiceTaxes;
    }

    public function addInvoiceTax(InvoiceTax $invoiceTax): self
    {
        $this->invoiceTaxes->add($invoiceTax);
        $invoiceTax->setCreditNote($this);

        return $this;
    }

    public function removeInvoiceTax(InvoiceTax $invoiceTax): self
    {
        $this->invoiceTaxes->removeElement($invoiceTax);

        return $this;
    }

    public function __toString(): string
    {
        return $this->creditNoteId;
    }
}
