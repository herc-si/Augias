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

namespace Augias\InvoiceBundle\Report;

use Augias\ClientBundle\Entity\Client;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use function usort;

/**
 * A client's statement of account: everything that moved what they owe, in
 * date order, with the balance after each line.
 *
 *  - an invoice issued raises what the client owes;
 *  - a payment taken lowers it, whatever it was paid with;
 *  - a credit note issued lowers it;
 *  - a credit note paid back to the client raises it again.
 *
 * A credit note set against an invoice is not a line of its own: the credit
 * note already came off the balance when it was issued.
 *
 * Drafts and cancelled invoices are left out. A positive balance is owed by
 * the client; a negative one is owed to them.
 *
 * @see \Augias\InvoiceBundle\Tests\Report\ClientStatementTest
 */
final readonly class ClientStatement
{
    public const string INVOICE = 'invoice';

    public const string PAYMENT = 'payment';

    public const string CREDIT_NOTE = 'credit_note';

    public const string REFUND = 'refund';

    /** Same day: the invoice before its payment, the credit note before its refund. */
    private const array ORDER = [self::INVOICE => 0, self::CREDIT_NOTE => 1, self::PAYMENT => 2, self::REFUND => 3];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $from first day shown; what happened before it is summed into the opening balance
     * @param DateTimeImmutable|null $to   last day shown
     *
     * @return array{opening: BigInteger, lines: list<array{date: DateTimeImmutable, kind: string, reference: string, id: string|null, debit: BigInteger, credit: BigInteger, balance: BigInteger}>, debit: BigInteger, credit: BigInteger, closing: BigInteger}
     */
    public function forClient(Client $client, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $from = $from?->setTime(0, 0);
        $to = $to?->setTime(23, 59, 59);

        $moves = [...$this->invoices($client), ...$this->creditNotes($client), ...$this->payments($client), ...$this->refunds($client)];

        usort($moves, static fn (array $a, array $b): int => [$a['date']->format('Y-m-d'), self::ORDER[$a['kind']], $a['reference']] <=> [$b['date']->format('Y-m-d'), self::ORDER[$b['kind']], $b['reference']]);

        $zero = BigInteger::zero();
        $balance = $zero;
        $opening = $zero;
        $debit = $zero;
        $credit = $zero;
        $lines = [];

        foreach ($moves as $move) {
            if ($to instanceof DateTimeImmutable && $move['date'] > $to) {
                break;
            }

            $balance = $balance->plus($move['debit'])->minus($move['credit']);

            if ($from instanceof DateTimeImmutable && $move['date'] < $from) {
                $opening = $balance;

                continue;
            }

            $debit = $debit->plus($move['debit']);
            $credit = $credit->plus($move['credit']);
            $lines[] = $move + ['balance' => $balance];
        }

        return ['opening' => $opening, 'lines' => $lines, 'debit' => $debit, 'credit' => $credit, 'closing' => $balance];
    }

    /**
     * @return list<array{date: DateTimeImmutable, kind: string, reference: string, id: string|null, debit: BigInteger, credit: BigInteger}>
     */
    private function invoices(Client $client): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.id AS id', 'i.invoiceId AS reference', 'i.invoiceDate AS date', 'i.total AS amount')
            ->from(Invoice::class, 'i')
            ->where('i.client = :client')
            ->andWhere('i.status NOT IN (:excluded)')
            ->setParameter('client', $client->getId(), UlidType::NAME)
            ->setParameter('excluded', [InvoiceStatus::New->value, InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->getQuery()
            ->getArrayResult();

        return $this->moves($rows, self::INVOICE, debit: true);
    }

    /**
     * @return list<array{date: DateTimeImmutable, kind: string, reference: string, id: string|null, debit: BigInteger, credit: BigInteger}>
     */
    private function creditNotes(Client $client): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('n.id AS id', 'n.creditNoteId AS reference', 'n.creditNoteDate AS date', 'n.total AS amount')
            ->from(CreditNote::class, 'n')
            ->where('n.client = :client')
            ->andWhere('n.status IN (:issued)')
            ->setParameter('client', $client->getId(), UlidType::NAME)
            ->setParameter('issued', [CreditNoteStatus::Issued->value, CreditNoteStatus::Settled->value])
            ->getQuery()
            ->getArrayResult();

        return $this->moves($rows, self::CREDIT_NOTE, debit: false);
    }

    /**
     * @return list<array{date: DateTimeImmutable, kind: string, reference: string, id: string|null, debit: BigInteger, credit: BigInteger}>
     */
    private function payments(Client $client): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            // The invoice the payment settled is what a reader looks for; the payment has no number of its own.
            ->select('i.id AS id', 'i.invoiceId AS reference', 'p.created AS date', 'p.totalAmount AS amount')
            ->from(Payment::class, 'p')
            ->leftJoin('p.invoice', 'i')
            ->where('p.client = :client')
            ->andWhere('p.status = :captured')
            ->setParameter('client', $client->getId(), UlidType::NAME)
            ->setParameter('captured', PaymentStatus::Captured->value)
            ->getQuery()
            ->getArrayResult();

        return $this->moves($rows, self::PAYMENT, debit: false);
    }

    /**
     * @return list<array{date: DateTimeImmutable, kind: string, reference: string, id: string|null, debit: BigInteger, credit: BigInteger}>
     */
    private function refunds(Client $client): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('n.id AS id', 'n.creditNoteId AS reference', 'a.allocatedOn AS date', 'a.amount AS amount')
            ->from(CreditNoteAllocation::class, 'a')
            ->innerJoin('a.creditNote', 'n')
            ->where('n.client = :client')
            ->andWhere('a.kind = :refund')
            ->setParameter('client', $client->getId(), UlidType::NAME)
            ->setParameter('refund', AllocationKind::Refund->value)
            ->getQuery()
            ->getArrayResult();

        return $this->moves($rows, self::REFUND, debit: true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<array{date: DateTimeImmutable, kind: string, reference: string, id: string|null, debit: BigInteger, credit: BigInteger}>
     */
    private function moves(array $rows, string $kind, bool $debit): array
    {
        $moves = [];

        foreach ($rows as $row) {
            if (! $row['date'] instanceof DateTimeInterface) {
                continue;
            }

            $amount = BigInteger::of((string) $row['amount']);

            $moves[] = [
                'date' => DateTimeImmutable::createFromInterface($row['date']),
                'kind' => $kind,
                'reference' => (string) ($row['reference'] ?? ''),
                'id' => null === $row['id'] ? null : (string) $row['id'],
                'debit' => $debit ? $amount : BigInteger::zero(),
                'credit' => $debit ? BigInteger::zero() : $amount,
            ];
        }

        return $moves;
    }
}
