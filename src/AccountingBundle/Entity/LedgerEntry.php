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

namespace Augias\AccountingBundle\Entity;

use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\LedgerEntrySource;
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of one of the two statutory books — a receipt in the livre des
 * recettes or a purchase in the registre des achats. Both live in this table
 * and are told apart by {@see $book}: they carry identical columns and are
 * always read the same way, only the direction of the money differs.
 *
 * This is cash accounting, so {@see $entryDate} is the date the money actually
 * moved, never the invoice date. Most entries are written by the feeders in
 * `Listener/Doctrine/` from a captured {@see \Augias\PaymentBundle\Entity\Payment}
 * or a {@see \Augias\BillBundle\Entity\BillPayment}; the rest are typed in by
 * hand for money that never went through a document.
 *
 * Entries are mutable only while their {@see $period} is open. Closing assigns
 * {@see $sequenceNumber}, computes {@see $hash} over the previous entry's hash
 * and sets {@see $lockedAt}, after which a Doctrine listener refuses any
 * further change — corrections then have to be made as a reversing entry.
 *
 * @see \Augias\AccountingBundle\Tests\Entity\LedgerEntryTest
 */
#[ORM\Table(name: LedgerEntry::TABLE_NAME)]
#[ORM\Index(name: 'idx_ledger_book_date', columns: ['company_id', 'book', 'entry_date'])]
#[ORM\UniqueConstraint(name: 'unique_ledger_source', columns: ['company_id', 'book', 'source', 'source_id'])]
#[ORM\Entity(repositoryClass: LedgerEntryRepository::class)]
class LedgerEntry
{
    final public const string TABLE_NAME = 'accounting_ledger_entries';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'book', type: Types::STRING, length: 10, enumType: LedgerBook::class)]
    private LedgerBook $book;

    /**
     * Gapless number within the company's book, assigned at closing time in
     * ({@see $entryDate}, {@see TimeStampable::$created}) order. Null while the
     * period is still open, since an entry that may yet be deleted cannot hold
     * a number without tearing a hole in the sequence.
     */
    #[ORM\Column(name: 'sequence_number', type: Types::INTEGER, nullable: true)]
    private ?int $sequenceNumber = null;

    /**
     * When the money moved. Not the invoice date — this is cash accounting.
     */
    #[ORM\Column(name: 'entry_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private DateTimeImmutable $entryDate;

    /**
     * "Nature de l'opération" — what the money was for.
     */
    #[ORM\Column(name: 'label', type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label = '';

    /**
     * The other party's name as it stood when the entry was made. Denormalised
     * on purpose: the book has to stay readable after a client or supplier is
     * deleted, and it must not retroactively change if they are renamed.
     */
    #[ORM\Column(name: 'counterparty_name', type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $counterpartyName = '';

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'counterparty_id', nullable: true, onDelete: 'SET NULL')]
    private ?Client $counterparty = null;

    /**
     * "Référence de la pièce justificative" — the invoice or receipt number.
     */
    #[ORM\Column(name: 'document_reference', type: Types::STRING, length: 125, nullable: true)]
    #[Assert\Length(max: 125)]
    private ?string $documentReference = null;

    /**
     * Minor units, signed: a refund or a reversing entry is negative.
     */
    #[ORM\Column(name: 'amount', type: BigIntegerType::NAME)]
    private BigNumber $amount;

    #[ORM\Column(name: 'currency_code', type: Types::STRING, length: 3)]
    private string $currencyCode;

    /**
     * The net share of {@see $amount} — what was received before tax.
     *
     * Null, not zero, when tax does not apply: a company in franchise en base
     * has no VAT to separate out, and a zero there would claim the sale was
     * taxable at nothing. Set together with {@see $taxAmount}, and always
     * exactly {@see $amount} minus it, so the two can be summed independently
     * without drifting apart.
     */
    #[ORM\Column(name: 'net_amount', type: BigIntegerType::NAME, nullable: true)]
    private ?BigNumber $netAmount = null;

    /**
     * The tax share of {@see $amount} — collected on a sale, paid on a
     * purchase. Zero is meaningful here and means a taxable operation that bore
     * no tax, such as a zero-rated or reverse-charge sale.
     */
    #[ORM\Column(name: 'tax_amount', type: BigIntegerType::NAME, nullable: true)]
    private ?BigNumber $taxAmount = null;

    /**
     * The same tax, split by rate: a VAT return declares a base and a tax per
     * rate, never one lump, so the split has to be recorded when the entry is
     * written. Entries become immutable once sealed, which is why this is
     * stored rather than derived later.
     *
     * @var list<array{rate: string, category: string, base: string, tax: string}>|null
     */
    #[ORM\Column(name: 'tax_breakdown', type: Types::JSON, nullable: true)]
    private ?array $taxBreakdown = null;

    /**
     * Only meaningful on the revenue side, where ceilings and contribution
     * rates depend on it. Always null for a purchase.
     */
    #[ORM\Column(name: 'activity_nature', type: Types::STRING, length: 20, nullable: true, enumType: ActivityNature::class)]
    private ?ActivityNature $activityNature = null;

    #[ORM\Column(name: 'settlement_method', type: Types::STRING, length: 20, nullable: true, enumType: SettlementMethod::class)]
    private ?SettlementMethod $settlementMethod = null;

    #[ORM\Column(name: 'source', type: Types::STRING, length: 20, enumType: LedgerEntrySource::class)]
    private LedgerEntrySource $source = LedgerEntrySource::Manual;

    /**
     * The id of the Payment or BillPayment behind this entry. Together with
     * {@see $source} it is what the unique index deduplicates on, so a payment
     * re-flushed any number of times still yields exactly one entry. Null for
     * manual entries — SQL leaves NULLs out of a unique index, which is exactly
     * what is wanted since nothing identifies one hand-typed entry from another.
     */
    #[ORM\Column(name: 'source_id', type: UlidType::NAME, nullable: true)]
    private ?Ulid $sourceId = null;

    #[ORM\ManyToOne(targetEntity: AccountingPeriod::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'period_id', nullable: true, onDelete: 'SET NULL')]
    private ?AccountingPeriod $period = null;

    /**
     * True when the entry had to be filed into a later period than its own date
     * calls for, because the period it belongs to was already closed. The date
     * is kept truthful and the discrepancy is flagged rather than hidden.
     */
    #[ORM\Column(name: 'late_entry', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $lateEntry = false;

    /**
     * SHA-256 over this entry's canonical fields and the previous entry's hash.
     * Set at closing time; null while the period is open.
     */
    #[ORM\Column(name: 'entry_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(name: 'previous_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $previousHash = null;

    #[ORM\Column(name: 'locked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lockedAt = null;

    /**
     * Points at the entry this one reverses, when it is a correction to a
     * locked entry.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reverses_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $reverses = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->amount = BigInteger::zero();
        $this->entryDate = new DateTimeImmutable('today');
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getBook(): LedgerBook
    {
        return $this->book;
    }

    public function setBook(LedgerBook $book): self
    {
        $this->book = $book;

        return $this;
    }

    public function getSequenceNumber(): ?int
    {
        return $this->sequenceNumber;
    }

    public function setSequenceNumber(?int $sequenceNumber): self
    {
        $this->sequenceNumber = $sequenceNumber;

        return $this;
    }

    public function getEntryDate(): DateTimeImmutable
    {
        return $this->entryDate;
    }

    public function setEntryDate(DateTimeImmutable $entryDate): self
    {
        $this->entryDate = $entryDate->setTime(0, 0);

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getCounterpartyName(): string
    {
        return $this->counterpartyName;
    }

    public function setCounterpartyName(string $counterpartyName): self
    {
        $this->counterpartyName = $counterpartyName;

        return $this;
    }

    public function getCounterparty(): ?Client
    {
        return $this->counterparty;
    }

    /**
     * Also refreshes the denormalised name, so the two never disagree at the
     * moment the entry is written.
     */
    public function setCounterparty(?Client $counterparty): self
    {
        $this->counterparty = $counterparty;

        if ($counterparty instanceof Client && $this->counterpartyName === '') {
            $this->counterpartyName = (string) $counterparty->getName();
        }

        return $this;
    }

    public function getDocumentReference(): ?string
    {
        return $this->documentReference;
    }

    public function setDocumentReference(?string $documentReference): self
    {
        $this->documentReference = $documentReference;

        return $this;
    }

    public function getAmount(): BigNumber
    {
        return $this->amount;
    }

    public function setAmount(BigNumber $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getNetAmount(): ?BigNumber
    {
        return $this->netAmount;
    }

    public function getTaxAmount(): ?BigNumber
    {
        return $this->taxAmount;
    }

    /**
     * @return list<array{rate: string, category: string, base: string, tax: string}>|null
     */
    public function getTaxBreakdown(): ?array
    {
        return $this->taxBreakdown;
    }

    /**
     * Whether tax was worked out for this entry at all. False for a company
     * outside the scope of VAT, and for a purchase, whose supplier bill records
     * no tax to split.
     */
    public function hasTax(): bool
    {
        return $this->taxAmount instanceof BigNumber;
    }

    /**
     * Written as one move because the three are one fact. Passing them
     * separately would allow a net without its tax, which no reader could make
     * sense of.
     *
     * @param list<array{rate: string, category: string, base: string, tax: string}> $breakdown
     */
    public function setTax(BigNumber $net, BigNumber $tax, array $breakdown): self
    {
        $this->netAmount = $net;
        $this->taxAmount = $tax;
        $this->taxBreakdown = $breakdown;

        return $this;
    }

    /**
     * Back to no tax at all, which is not the same as a tax of zero.
     *
     * Needed because a hand-written entry can be corrected: a rate entered by
     * mistake has to be removable, and leaving a zero behind would state that
     * the operation was taxable and bore nothing — see {@see $netAmount}.
     */
    public function clearTax(): self
    {
        $this->netAmount = null;
        $this->taxAmount = null;
        $this->taxBreakdown = null;

        return $this;
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

    public function getMoney(): Money
    {
        return new Money((string) $this->amount, new Currency($this->currencyCode));
    }

    /**
     * The tax separated out of {@see $amount}, as money — zero when none was.
     *
     * For display only, which is why it flattens the distinction {@see hasTax}
     * keeps: a register has to show a figure in every cell of a column it
     * carries. Read the stored amount, not this, to tell a sale that bore no
     * tax from one where tax never applied.
     */
    public function getTaxMoney(): Money
    {
        return new Money((string) ($this->taxAmount ?? BigInteger::zero()), new Currency($this->currencyCode));
    }

    /**
     * What was received before tax — the whole amount when no tax was
     * separated out of it.
     */
    public function getNetMoney(): Money
    {
        return new Money((string) ($this->netAmount ?? $this->amount), new Currency($this->currencyCode));
    }

    public function getActivityNature(): ?ActivityNature
    {
        return $this->activityNature;
    }

    public function setActivityNature(?ActivityNature $activityNature): self
    {
        $this->activityNature = $activityNature;

        return $this;
    }

    public function getSettlementMethod(): ?SettlementMethod
    {
        return $this->settlementMethod;
    }

    public function setSettlementMethod(?SettlementMethod $settlementMethod): self
    {
        $this->settlementMethod = $settlementMethod;

        return $this;
    }

    public function getSource(): LedgerEntrySource
    {
        return $this->source;
    }

    public function setSource(LedgerEntrySource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getSourceId(): ?Ulid
    {
        return $this->sourceId;
    }

    public function setSourceId(?Ulid $sourceId): self
    {
        $this->sourceId = $sourceId;

        return $this;
    }

    public function getPeriod(): ?AccountingPeriod
    {
        return $this->period;
    }

    public function setPeriod(?AccountingPeriod $period): self
    {
        $this->period = $period;

        return $this;
    }

    public function isLateEntry(): bool
    {
        return $this->lateEntry;
    }

    public function setLateEntry(bool $lateEntry): self
    {
        $this->lateEntry = $lateEntry;

        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): self
    {
        $this->hash = $hash;

        return $this;
    }

    public function getPreviousHash(): ?string
    {
        return $this->previousHash;
    }

    public function setPreviousHash(?string $previousHash): self
    {
        $this->previousHash = $previousHash;

        return $this;
    }

    public function getLockedAt(): ?DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?DateTimeImmutable $lockedAt): self
    {
        $this->lockedAt = $lockedAt;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->lockedAt instanceof DateTimeImmutable;
    }

    public function getReverses(): ?self
    {
        return $this->reverses;
    }

    public function setReverses(?self $reverses): self
    {
        $this->reverses = $reverses;

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

    /**
     * Whether a user may edit this entry at all. Automatic entries mirror a
     * payment record and would drift out of sync with it, so only their
     * bookkeeping-side fields (activity nature, notes) are ever editable —
     * see {@see \Augias\AccountingBundle\Form\Type\LedgerEntryType}.
     */
    public function isEditable(): bool
    {
        return ! $this->isLocked() && $this->source === LedgerEntrySource::Manual;
    }
}
