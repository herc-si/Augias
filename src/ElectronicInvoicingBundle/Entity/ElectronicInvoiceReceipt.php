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

namespace Augias\ElectronicInvoicingBundle\Entity;

use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceReceiptRepository;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * One electronic invoice received BY this company from a supplier, imported
 * from an {@see \Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceReceiverInterface}
 * provider — the inbound counterpart of {@see ElectronicInvoiceSubmission}.
 *
 * Deliberately its own entity rather than reusing PaymentBundle's Payment:
 * a Payment is a captured transaction against one of this company's own
 * Invoices, tied to a Payum gateway — a received invoice is the opposite
 * direction (an obligation arriving from someone else) and predates any
 * payment decision, so forcing it through Payment's shape (and API surface)
 * would misrepresent both.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Entity\ElectronicInvoiceReceiptTest
 */
#[ORM\Entity(repositoryClass: ElectronicInvoiceReceiptRepository::class)]
#[ORM\Table(name: ElectronicInvoiceReceipt::TABLE_NAME)]
#[ORM\UniqueConstraint(name: 'einvoicing_receipt_provider_ref', columns: ['company_id', 'provider', 'external_reference'])]
class ElectronicInvoiceReceipt
{
    public const string TABLE_NAME = 'einvoicing_receipt';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $provider;

    /**
     * The provider's own id for this invoice — the de-duplication key that
     * stops the same inbound invoice being imported twice across poll runs.
     */
    #[ORM\Column(name: 'external_reference', type: Types::STRING, length: 255)]
    private string $externalReference;

    #[ORM\Column(name: 'invoice_number', type: Types::STRING, length: 255, nullable: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(name: 'seller_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $sellerName = null;

    #[ORM\Column(name: 'seller_identifier', type: Types::STRING, length: 64, nullable: true)]
    private ?string $sellerIdentifier = null;

    #[ORM\Column(name: 'issue_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $issueDate = null;

    #[ORM\Column(name: 'total_amount', type: BigIntegerType::NAME, nullable: true)]
    private ?BigNumber $totalAmount = null;

    #[ORM\Column(name: 'currency_code', type: Types::STRING, length: 3, nullable: true)]
    private ?string $currencyCode = null;

    /**
     * The provider's latest known status for this invoice (e.g. SUPER PDP's
     * `fr:*`/`api:*` codes) at the time it was imported — purely informational,
     * unlike {@see ElectronicInvoiceSubmission::$statusCode} this isn't refreshed
     * afterwards, since once WE have received the document its lifecycle is
     * this company's to manage, not the provider's.
     */
    #[ORM\Column(name: 'status_code', type: Types::STRING, length: 64, nullable: true)]
    private ?string $statusCode = null;

    /**
     * The VAT the supplier charged, as the invoice states it (BT-110) — what
     * the bill made from this receipt deducts, so that nobody types it again.
     */
    #[ORM\Column(name: 'tax_amount', type: BigIntegerType::NAME, nullable: true)]
    private ?BigNumber $taxAmount = null;

    /**
     * Goods or services, from the invoice's billing framework (BT-23): with
     * the supplier's option for debits, it decides when that VAT is
     * deductible. Null for a mixed invoice.
     */
    #[ORM\Column(name: 'supply_type', type: Types::STRING, length: 10, nullable: true, enumType: SupplyType::class)]
    private ?SupplyType $supplyType = null;

    #[ORM\Column(name: 'supplier_vat_on_debits', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $supplierVatOnDebits = false;

    /**
     * Relative path from the project root to the downloaded document on local
     * disk (e.g. `var/einvoicing/incoming/{companyId}/{id}.pdf`), following the
     * same convention as {@see \Augias\CoreBundle\Entity\ExportJob::$archivePath}.
     * Null until {@see \Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceReceiptManager}
     * downloads it.
     */
    #[ORM\Column(name: 'document_path', type: Types::STRING, length: 512, nullable: true)]
    private ?string $documentPath = null;

    #[ORM\Column(name: 'document_mime_type', type: Types::STRING, length: 100, nullable: true)]
    private ?string $documentMimeType = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getExternalReference(): string
    {
        return $this->externalReference;
    }

    public function setExternalReference(string $externalReference): self
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): self
    {
        $this->invoiceNumber = $invoiceNumber;

        return $this;
    }

    public function getSellerName(): ?string
    {
        return $this->sellerName;
    }

    public function setSellerName(?string $sellerName): self
    {
        $this->sellerName = $sellerName;

        return $this;
    }

    public function getSellerIdentifier(): ?string
    {
        return $this->sellerIdentifier;
    }

    public function setSellerIdentifier(?string $sellerIdentifier): self
    {
        $this->sellerIdentifier = $sellerIdentifier;

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

    public function getTotalAmount(): ?BigNumber
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(?BigNumber $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;

        return $this;
    }

    /**
     * Combines totalAmount/currencyCode into a Money value object, the way
     * {@see \Augias\PaymentBundle\Entity\Payment::getAmount()} does —
     * null when either half is missing (a provider that didn't report an
     * amount), rather than guessing a default currency.
     */
    public function getAmount(): ?Money
    {
        if ($this->totalAmount === null || $this->currencyCode === null) {
            return null;
        }

        return new Money((string) $this->totalAmount, new Currency($this->currencyCode));
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

    public function getSupplyType(): ?SupplyType
    {
        return $this->supplyType;
    }

    public function setSupplyType(?SupplyType $supplyType): self
    {
        $this->supplyType = $supplyType;

        return $this;
    }

    public function isSupplierVatOnDebits(): bool
    {
        return $this->supplierVatOnDebits;
    }

    public function setSupplierVatOnDebits(bool $supplierVatOnDebits): self
    {
        $this->supplierVatOnDebits = $supplierVatOnDebits;

        return $this;
    }

    /**
     * The company's answer to this invoice, if it has given one — read off
     * the lifecycle status, which is where the platform keeps it too.
     */
    public function getResponse(): ?ReceiptResponse
    {
        return ReceiptResponse::tryFrom((string) $this->statusCode);
    }

    public function getStatusCode(): ?string
    {
        return $this->statusCode;
    }

    public function setStatusCode(?string $statusCode): self
    {
        $this->statusCode = $statusCode;

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
        return $this->documentPath !== null;
    }
}
