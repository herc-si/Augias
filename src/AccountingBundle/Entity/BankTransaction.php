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

use Augias\AccountingBundle\Enum\BankTransactionStatus;
use Augias\AccountingBundle\Repository\BankTransactionRepository;
use Augias\BillBundle\Entity\BillPayment;
use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\PaymentBundle\Entity\Payment;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function mb_substr;

/**
 * One line of an imported bank statement.
 *
 * The amount is signed — money in is positive, money out negative — and in
 * the account's minor units. A line is matched to the payment it is the trace
 * of: a client's {@see Payment} for money in, a {@see BillPayment} for money
 * out. Matching never writes the books itself; the payment does, as it always
 * has, so a line matched to an existing payment adds nothing twice.
 *
 * The fingerprint is what makes a statement safe to import again: the bank's
 * own reference when it gives one, a hash of the line otherwise.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\StatementImporterTest
 */
#[ORM\Table(name: BankTransaction::TABLE_NAME)]
#[ORM\Index(name: 'idx_bank_transaction_status', columns: ['bank_account_id', 'status', 'booking_date'])]
#[ORM\UniqueConstraint(name: 'unique_bank_transaction_fingerprint', columns: ['bank_account_id', 'fingerprint'])]
#[ORM\Entity(repositoryClass: BankTransactionRepository::class)]
class BankTransaction
{
    final public const string TABLE_NAME = 'accounting_bank_transactions';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(name: 'bank_account_id', nullable: false, onDelete: 'CASCADE')]
    private BankAccount $bankAccount;

    #[ORM\Column(name: 'booking_date', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $bookingDate;

    #[ORM\Column(name: 'amount', type: BigIntegerType::NAME)]
    private BigNumber $amount;

    #[ORM\Column(name: 'label', type: Types::STRING, length: 255)]
    private string $label = '';

    #[ORM\Column(name: 'counterparty_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $counterpartyName = null;

    #[ORM\Column(name: 'reference', type: Types::STRING, length: 255, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'fingerprint', type: Types::STRING, length: 64)]
    private string $fingerprint;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, enumType: BankTransactionStatus::class)]
    private BankTransactionStatus $status = BankTransactionStatus::Unmatched;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', nullable: true, onDelete: 'SET NULL')]
    private ?Payment $payment = null;

    #[ORM\ManyToOne(targetEntity: BillPayment::class)]
    #[ORM\JoinColumn(name: 'bill_payment_id', nullable: true, onDelete: 'SET NULL')]
    private ?BillPayment $billPayment = null;

    public function __construct(BankAccount $bankAccount, DateTimeImmutable $bookingDate, BigNumber $amount, string $label, string $fingerprint)
    {
        $this->bankAccount = $bankAccount;
        $this->bookingDate = $bookingDate;
        $this->amount = $amount->toBigInteger();
        $this->label = mb_substr($label, 0, 255);
        $this->fingerprint = $fingerprint;
        $this->setCompany($bankAccount->getCompany());
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getBankAccount(): BankAccount
    {
        return $this->bankAccount;
    }

    public function getBookingDate(): DateTimeImmutable
    {
        return $this->bookingDate;
    }

    public function getAmount(): BigInteger
    {
        return $this->amount->toBigInteger();
    }

    public function isCredit(): bool
    {
        return $this->getAmount()->isPositive();
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCounterpartyName(): ?string
    {
        return $this->counterpartyName;
    }

    public function setCounterpartyName(?string $counterpartyName): self
    {
        $this->counterpartyName = null === $counterpartyName ? null : mb_substr($counterpartyName, 0, 255);

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = null === $reference ? null : mb_substr($reference, 0, 255);

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getStatus(): BankTransactionStatus
    {
        return $this->status;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function getBillPayment(): ?BillPayment
    {
        return $this->billPayment;
    }

    public function matchPayment(Payment $payment): self
    {
        $this->payment = $payment;
        $this->billPayment = null;
        $this->status = BankTransactionStatus::Matched;

        return $this;
    }

    public function matchBillPayment(BillPayment $billPayment): self
    {
        $this->billPayment = $billPayment;
        $this->payment = null;
        $this->status = BankTransactionStatus::Matched;

        return $this;
    }

    public function ignore(): self
    {
        $this->payment = null;
        $this->billPayment = null;
        $this->status = BankTransactionStatus::Ignored;

        return $this;
    }

    /**
     * Back to unmatched. The payment it was tied to stays: undoing a match is
     * not undoing a payment, which is corrected where payments are.
     */
    public function reopen(): self
    {
        $this->payment = null;
        $this->billPayment = null;
        $this->status = BankTransactionStatus::Unmatched;

        return $this;
    }
}
