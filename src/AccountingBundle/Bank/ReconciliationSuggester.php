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

namespace Augias\AccountingBundle\Bank;

use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Entity\BillPayment;
use Augias\BillBundle\Enum\BillStatus;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\UnicodeString;
use function abs;
use function array_slice;
use function max;
use function str_contains;
use function usort;

/**
 * What a bank line is most likely the trace of.
 *
 * Only exact amounts are offered — a near miss is a different payment more
 * often than a typo. Money in: a payment already recorded around that date,
 * or an invoice still owed exactly that much. Money out: the same with the
 * supplier's side. The invoice or bill number found in the bank's label, the
 * other party's name found there, and a close date rank a candidate higher.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\ReconciliationTest
 */
final readonly class ReconciliationSuggester
{
    /** How far a recorded payment's date may be from the bank's. */
    private const int DAYS = 10;

    private const int SHOWN = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Suggestion> best first
     */
    public function suggest(BankTransaction $line): array
    {
        $suggestions = $line->isCredit() ? $this->forMoneyIn($line) : $this->forMoneyOut($line);

        usort($suggestions, static fn (Suggestion $a, Suggestion $b): int => $b->score <=> $a->score);

        return array_slice($suggestions, 0, self::SHOWN);
    }

    /**
     * @return list<Suggestion>
     */
    private function forMoneyIn(BankTransaction $line): array
    {
        $amount = $line->getAmount();
        $currency = $line->getBankAccount()->getCurrencyCode();
        $suggestions = [];

        /** @var list<Payment> $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.status = :captured')
            ->andWhere('p.currencyCode = :currency')
            ->andWhere('p.totalAmount = :amount')
            ->andWhere('p.completed BETWEEN :from AND :to')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . BankTransaction::class . ' t WHERE t.payment = p)')
            ->setParameter('captured', PaymentStatus::Captured->value)
            ->setParameter('currency', $currency)
            ->setParameter('amount', (string) $amount)
            ->setParameter('from', $line->getBookingDate()->modify('-' . self::DAYS . ' days'), Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $line->getBookingDate()->modify('+' . (self::DAYS + 1) . ' days'), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();

        foreach ($payments as $payment) {
            $invoice = $payment->getInvoice();
            $client = (string) $payment->getClient()?->getName();
            $number = $invoice?->getInvoiceId() ?? '';

            $suggestions[] = new Suggestion(
                Suggestion::PAYMENT,
                (string) $payment->getId(),
                $number,
                $client,
                $payment->getCompleted(),
                // Already recorded: the likeliest reading of a line that matches it.
                60 + $this->bonus($line, $number, $client, $payment->getCompleted()),
            );
        }

        /** @var list<Invoice> $invoices */
        $invoices = $this->entityManager->createQueryBuilder()
            ->select('i', 'c')
            ->from(Invoice::class, 'i')
            ->join('i.client', 'c')
            ->where('i.status IN (:open)')
            ->andWhere('i.balance = :amount')
            ->andWhere('c.currencyCode = :currency')
            ->setParameter('open', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value])
            ->setParameter('amount', (string) $amount)
            ->setParameter('currency', $currency)
            ->getQuery()
            ->getResult();

        foreach ($invoices as $invoice) {
            $client = (string) $invoice->getClient()?->getName();

            $suggestions[] = new Suggestion(
                Suggestion::INVOICE,
                (string) $invoice->getId(),
                $invoice->getInvoiceId(),
                $client,
                null === $invoice->getDue() ? null : DateTimeImmutable::createFromInterface($invoice->getDue()),
                50 + $this->bonus($line, $invoice->getInvoiceId(), $client, null),
            );
        }

        return $suggestions;
    }

    /**
     * @return list<Suggestion>
     */
    private function forMoneyOut(BankTransaction $line): array
    {
        $amount = $line->getAmount()->abs();
        $currency = $line->getBankAccount()->getCurrencyCode();
        $suggestions = [];

        /** @var list<BillPayment> $payments */
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p', 'b')
            ->from(BillPayment::class, 'p')
            ->join('p.bill', 'b')
            ->where('p.currencyCode = :currency')
            ->andWhere('p.amount = :amount')
            ->andWhere('p.paidDate BETWEEN :from AND :to')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . BankTransaction::class . ' t WHERE t.billPayment = p)')
            ->setParameter('currency', $currency)
            ->setParameter('amount', (string) $amount)
            ->setParameter('from', $line->getBookingDate()->modify('-' . self::DAYS . ' days'), Types::DATE_IMMUTABLE)
            ->setParameter('to', $line->getBookingDate()->modify('+' . self::DAYS . ' days'), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        foreach ($payments as $payment) {
            $bill = $payment->getBill();
            $supplier = $bill->getSupplier()->getName() ?? '';

            $suggestions[] = new Suggestion(
                Suggestion::BILL_PAYMENT,
                (string) $payment->getId(),
                (string) $bill->getBillNumber(),
                $supplier,
                $payment->getPaidDate(),
                60 + $this->bonus($line, (string) $bill->getBillNumber(), $supplier, $payment->getPaidDate()),
            );
        }

        /** @var list<Bill> $bills */
        $bills = $this->entityManager->createQueryBuilder()
            ->select('b', 's')
            ->from(Bill::class, 'b')
            ->join('b.supplier', 's')
            ->where('b.status IN (:open)')
            ->andWhere('b.currencyCode = :currency')
            ->setParameter('open', [BillStatus::Pending->value, BillStatus::Overdue->value])
            ->setParameter('currency', $currency)
            ->getQuery()
            ->getResult();

        foreach ($bills as $bill) {
            // The balance is worked out from the payments, not stored.
            if (! BigInteger::of($bill->getBalance()->getAmount())->isEqualTo($amount)) {
                continue;
            }

            $supplier = $bill->getSupplier()->getName() ?? '';

            $suggestions[] = new Suggestion(
                Suggestion::BILL,
                (string) $bill->getId(),
                (string) $bill->getBillNumber(),
                $supplier,
                $bill->getDueDate(),
                50 + $this->bonus($line, (string) $bill->getBillNumber(), $supplier, null),
            );
        }

        return $suggestions;
    }

    private function bonus(BankTransaction $line, string $number, string $name, ?DateTimeImmutable $date): int
    {
        $haystack = $this->normalized($line->getLabel() . ' ' . $line->getCounterpartyName() . ' ' . $line->getReference());
        $bonus = 0;

        if ('' !== $number && str_contains($haystack, $this->normalized($number))) {
            $bonus += 30;
        }

        if ('' !== $name && str_contains($haystack, $this->normalized($name))) {
            $bonus += 15;
        }

        if (null !== $date) {
            $days = (int) abs(($line->getBookingDate()->getTimestamp() - $date->setTime(0, 0)->getTimestamp()) / 86400);
            $bonus += max(0, self::DAYS - $days);
        }

        return $bonus;
    }

    private function normalized(string $value): string
    {
        return (string) new UnicodeString($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '');
    }
}
