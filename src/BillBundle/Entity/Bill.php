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

namespace Augias\BillBundle\Entity;

use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\RecordKind;
use Augias\CoreBundle\Journal\Journalled;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * An invoice this company received from a supplier (a {@see Client} flagged
 * {@see Client::isSupplier()}) and owes money on — the accounts-payable
 * mirror of {@see \Augias\InvoiceBundle\Entity\Invoice}.
 * Either typed in directly (starts {@see BillStatus::Draft}) or created from
 * an already-received {@see ElectronicInvoiceReceipt} (starts
 * {@see BillStatus::Pending} directly, via {@see \Augias\BillBundle\Manager\BillManager::createFromReceipt()}).
 *
 * @see \Augias\BillBundle\Tests\Entity\BillTest
 */
#[ORM\Table(name: Bill::TABLE_NAME)]
#[ORM\Entity(repositoryClass: BillRepository::class)]
class Bill implements Journalled
{
    final public const string TABLE_NAME = 'bills';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false, onDelete: 'CASCADE')]
    private Client $supplier;

    /**
     * The supplier's own invoice number — distinct from this record's own id,
     * kept as free text since we don't control its format.
     */
    #[ORM\Column(name: 'bill_number', type: Types::STRING, length: 125, nullable: true)]
    private ?string $billNumber = null;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, enumType: BillStatus::class)]
    private BillStatus $status = BillStatus::Draft;

    #[ORM\Column(name: 'issue_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $issueDate = null;

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dueDate = null;

    #[ORM\Column(name: 'total_amount', type: BigIntegerType::NAME)]
    private BigNumber $totalAmount;

    /**
     * The VAT the supplier charged, as their document states it.
     *
     * Typed in rather than worked out: a supplier's bill is a document that
     * arrives, not a total the application computed, and its tax is whatever is
     * printed on it — several rates, rounding of their own, a partial
     * exemption. Asking for the figure is both simpler and truer than deriving
     * it from a rate.
     *
     * Null when tax does not apply, which is not the same as a bill that bore
     * none: a company outside the scope of VAT deducts nothing either way, and
     * a zero here would claim the supplier charged none.
     *
     * One figure, not a breakdown by rate, because that is how deductible VAT
     * is declared — a single box, unlike the tax collected on sales.
     */
    #[ORM\Column(name: 'tax_amount', type: BigIntegerType::NAME, nullable: true)]
    private ?BigNumber $taxAmount = null;

    #[ORM\Column(name: 'currency_code', type: Types::STRING, length: 3)]
    private string $currencyCode;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Provenance only — set when this bill was created from an imported
     * electronic invoice. Never required: a bill can be entered by hand with
     * no receipt behind it at all.
     */
    #[ORM\ManyToOne(targetEntity: ElectronicInvoiceReceipt::class)]
    #[ORM\JoinColumn(name: 'electronic_invoice_receipt_id', nullable: true, onDelete: 'SET NULL')]
    private ?ElectronicInvoiceReceipt $electronicInvoiceReceipt = null;

    /**
     * Relative path from the project root to a manually-uploaded document,
     * following the same `var/`-relative-path + Filesystem convention as
     * {@see \Augias\CoreBundle\Entity\ExportJob::$archivePath}. Null
     * when the bill instead points at a receipt's own document, or has none.
     */
    #[ORM\Column(name: 'document_path', type: Types::STRING, length: 512, nullable: true)]
    private ?string $documentPath = null;

    #[ORM\Column(name: 'document_mime_type', type: Types::STRING, length: 100, nullable: true)]
    private ?string $documentMimeType = null;

    /**
     * @var Collection<int, BillPayment>
     */
    #[ORM\OneToMany(targetEntity: BillPayment::class, mappedBy: 'bill', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['paidDate' => 'DESC'])]
    private Collection $payments;

    public function __construct()
    {
        $this->payments = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getSupplier(): Client
    {
        return $this->supplier;
    }

    public function setSupplier(Client $supplier): self
    {
        $this->supplier = $supplier;

        return $this;
    }

    /**
     * Whether a supplier has been set yet — a plain `getSupplier()` call
     * throws on a brand new, not-yet-persisted Bill since the property is
     * required and has no default, which `BillType` needs to check safely
     * (the `supplier` field is unmapped, so it fills its own default from
     * the entity rather than relying on the form's automatic mapping).
     */
    public function hasSupplier(): bool
    {
        return isset($this->supplier);
    }

    public function getBillNumber(): ?string
    {
        return $this->billNumber;
    }

    public function setBillNumber(?string $billNumber): self
    {
        $this->billNumber = $billNumber;

        return $this;
    }

    public function getStatus(): BillStatus
    {
        return $this->status;
    }

    public function setStatus(BillStatus $status): self
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
        $this->status = BillStatus::from($status);

        return $this;
    }

    public function getIssueDate(): ?DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function setIssueDate(?DateTimeImmutable $issueDate): self
    {
        $this->issueDate = $issueDate;

        return $this;
    }

    public function getDueDate(): ?DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?DateTimeImmutable $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getTotalAmount(): BigNumber
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(BigNumber $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getTaxAmount(): ?BigNumber
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(?BigNumber $taxAmount): self
    {
        $this->taxAmount = $taxAmount;

        return $this;
    }

    /**
     * What the bill came to before the supplier's tax — the whole of it when no
     * tax was recorded.
     */
    public function getNetAmount(): BigNumber
    {
        return $this->taxAmount instanceof BigNumber
            ? $this->totalAmount->toBigInteger()->minus($this->taxAmount)
            : $this->totalAmount;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;

        return $this;
    }

    public function getTotal(): Money
    {
        return new Money((string) $this->totalAmount, new Currency($this->currencyCode));
    }

    /**
     * The sum of every recorded {@see BillPayment} against this bill.
     */
    public function getPaidAmount(): BigNumber
    {
        $total = BigNumber::of(0);

        foreach ($this->payments as $payment) {
            $total = $total->plus($payment->getAmount());
        }

        return $total;
    }

    /**
     * What's still owed — never negative even if overpaid, since a negative
     * balance has no clear meaning here (unlike Invoice, there's no credit
     * concept on the accounts-payable side yet).
     */
    public function getBalance(): Money
    {
        $balance = $this->totalAmount->toBigDecimal()->minus($this->getPaidAmount()->toBigDecimal());

        if ($balance->isNegative()) {
            $balance = $balance->multipliedBy(0);
        }

        return new Money((string) $balance, new Currency($this->currencyCode));
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

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

    public function getElectronicInvoiceReceipt(): ?ElectronicInvoiceReceipt
    {
        return $this->electronicInvoiceReceipt;
    }

    public function setElectronicInvoiceReceipt(?ElectronicInvoiceReceipt $electronicInvoiceReceipt): self
    {
        $this->electronicInvoiceReceipt = $electronicInvoiceReceipt;

        return $this;
    }

    public function getDocumentPath(): ?string
    {
        return $this->documentPath;
    }

    public function setDocumentPath(?string $documentPath): self
    {
        $this->documentPath = $documentPath;

        return $this;
    }

    public function getDocumentMimeType(): ?string
    {
        return $this->documentMimeType;
    }

    public function setDocumentMimeType(?string $documentMimeType): self
    {
        $this->documentMimeType = $documentMimeType;

        return $this;
    }

    public function hasDocument(): bool
    {
        return $this->documentPath !== null || $this->electronicInvoiceReceipt?->hasDocument() === true;
    }

    /**
     * @return Collection<int, BillPayment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(BillPayment $payment): self
    {
        if (! $this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setBill($this);
        }

        return $this;
    }

    public function journalKind(): RecordKind
    {
        return RecordKind::Bill;
    }

    public function journalLabel(): string
    {
        return (string) $this->getBillNumber();
    }
}
