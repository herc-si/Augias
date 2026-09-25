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

namespace Augias\ElectronicInvoicingBundle\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\ElectronicInvoicingBundle\Provider\ReportedPayment;
use Augias\ElectronicInvoicingBundle\Provider\ReportedTransaction;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\PaymentBundle\Entity\Payment;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Augias\TaxBundle\Enum\TaxDirection;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use function array_map;
use function array_values;
use function in_array;

/**
 * What e-reporting declares for a sale to a private individual, and for the
 * money received for it — read off the invoice and the payment.
 *
 * A private individual has no e-invoicing address: the sale is not sent,
 * it is reported, in aggregate, by category (AFNOR XP Z12-012 annex A):
 * goods (TLB1), services (TPS1), and — for a company in franchise, which
 * charges no VAT — not subject to VAT (TNT1). A sale of both is one
 * transaction in each category.
 *
 * Payments are reported for services whose VAT falls due on payment: the
 * administration learns when that VAT became due. Not for goods (due on
 * delivery), nor under the option for debits, nor in franchise (no VAT).
 *
 * A credit note is the same sale going the other way: its transactions are
 * negative, and so is a refund of it. A credit note set against another
 * invoice moves no money — the client pays less, and the smaller payment on
 * that invoice is already what was received.
 *
 * Amounts in major units, two decimals: the documents are in euros.
 */
final readonly class ReportDataBuilder
{
    public function __construct(
        private TaxCalculatorInterface $taxCalculator,
        private SystemConfig $systemConfig,
    ) {
    }

    /**
     * Whether the client is a private individual — no SIRET, no SIREN, no VAT
     * number. A company without a French number is not one: that is an
     * international sale, reported differently.
     */
    public function isPrivateIndividual(?Client $client): bool
    {
        if (! $client instanceof Client) {
            return false;
        }

        foreach ($client->getTaxIdentifiers() as $identifier) {
            if (in_array($identifier->getLabel(), TaxIdentifierType::PROMINENT_LABELS, true) && '' !== (string) $identifier->getValue()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<ReportedTransaction> one per category the document sells
     *                                   in — negative for a credit note
     */
    public function transactions(Invoice | CreditNote $document): array
    {
        $exempt = $this->systemConfig->isVatExempt($document->getCompany());
        $currency = $document->getClient()?->getCurrencyCode() ?? 'EUR';
        $sign = $document instanceof CreditNote ? -1 : 1;
        // What a credit note takes back was delivered when the invoice said.
        $invoice = $document instanceof CreditNote ? $document->getCreditedInvoice() : $document;

        /** @var array<string, array<string, array{taxable: BigDecimal, tax: BigDecimal}>> $categories */
        $categories = [];

        foreach ($this->lines($document) as [$category, $subtotal, $rows]) {
            $category = $exempt ? 'TNT1' : $category;
            $subtotal = $subtotal->multipliedBy($sign);
            $rows = array_map(static fn (array $row): array => [$row[0], $row[1]->multipliedBy($sign)], $rows);

            if ([] === $rows) {
                $this->add($categories, $category, '0.00', $subtotal, BigDecimal::zero());

                continue;
            }

            foreach ($rows as [$rate, $tax]) {
                $this->add($categories, $category, $rate, $subtotal, $tax);
            }
        }

        $transactions = [];

        foreach ($categories as $category => $byRate) {
            $taxable = BigDecimal::zero();
            $tax = BigDecimal::zero();
            $subtotals = [];

            foreach ($byRate as $rate => $amounts) {
                $taxable = $taxable->plus($amounts['taxable']);
                $tax = $tax->plus($amounts['tax']);
                $subtotals[$rate] = ['taxable' => $this->major($amounts['taxable']), 'tax' => $this->major($amounts['tax'])];
            }

            $transactions[] = new ReportedTransaction(
                DateTimeImmutable::createFromInterface($document instanceof CreditNote ? $document->getCreditNoteDate() : $document->getInvoiceDate()),
                $currency,
                $category,
                $this->major($taxable),
                $this->major($tax),
                $subtotals,
                match ($category) {
                    'TLB1' => true === $invoice?->hasDistinctDeliveryDate() ? '35' : '3',
                    'TPS1' => $document->isVatOnDebits() ? '3' : '432',
                    default => null,
                },
            );
        }

        return $transactions;
    }

    /**
     * The payment's share of the invoice's services, by rate, tax included —
     * or null when there is nothing to report: no services, VAT on debits, a
     * company in franchise.
     */
    public function payment(Payment $payment): ?ReportedPayment
    {
        $invoice = $payment->getInvoice();

        if (! $invoice instanceof Invoice) {
            return null;
        }

        return $this->servicesShare(
            $invoice,
            BigDecimal::of($payment->getAmount()->getAmount()),
            $payment->getCompleted() ?? new DateTimeImmutable('today'),
            $payment->getAmount()->getCurrency()->getCode(),
        );
    }

    /**
     * A credit note paid back: the same share, going out — or null for an
     * offset, which moves no money, and wherever a payment would be null.
     */
    public function refund(CreditNoteAllocation $allocation): ?ReportedPayment
    {
        if (! $allocation->getKind()->movesMoney()) {
            return null;
        }

        $creditNote = $allocation->getCreditNote();

        return $this->servicesShare(
            $creditNote,
            $allocation->getAmount()->toBigDecimal(),
            $allocation->getAllocatedOn(),
            $creditNote->getClient()->getCurrencyCode() ?? 'EUR',
            -1,
        );
    }

    /**
     * @param BigDecimal $amount in minor units, of the document's total
     */
    private function servicesShare(Invoice | CreditNote $document, BigDecimal $amount, DateTimeImmutable $date, string $currency, int $sign = 1): ?ReportedPayment
    {
        if ($document->isVatOnDebits() || $this->systemConfig->isVatExempt($document->getCompany())) {
            return null;
        }

        /** @var array<string, BigDecimal> $services tax-included, by rate */
        $services = [];

        foreach ($this->lines($document) as [$category, $subtotal, $rows]) {
            if ('TPS1' !== $category) {
                continue;
            }

            foreach ([] === $rows ? [['0.00', BigDecimal::zero()]] : $rows as [$rate, $tax]) {
                $services[$rate] = ($services[$rate] ?? BigDecimal::zero())->plus($subtotal)->plus($tax);
            }
        }

        $total = $document->getTotal()->toBigDecimal();

        if ([] === $services || ! $total->isPositive()) {
            return null;
        }

        $ratio = $amount->isGreaterThanOrEqualTo($total) ? BigDecimal::one() : $amount->dividedBy($total, 10, RoundingMode::HalfEven);

        $amounts = [];

        foreach ($services as $rate => $share) {
            $amounts[$rate] = $this->major($share->multipliedBy($ratio)->multipliedBy($sign));
        }

        return new ReportedPayment($date, $currency, $amounts);
    }

    /**
     * The document's lines as sold: category, net, and VAT rows (rate,
     * amount). Disbursements are left out — money advanced for the client is
     * not a sale.
     *
     * @return list<array{0: string, 1: BigDecimal, 2: list<array{0: string, 1: BigDecimal}>}>
     */
    private function lines(Invoice | CreditNote $document): array
    {
        $result = $this->taxCalculator->calculate($document);
        $lines = array_values($document->getLines()->toArray());
        $out = [];

        foreach ($result->lineBreakdowns as $index => $breakdown) {
            if (($lines[$index] ?? null)?->isDisbursement() === true) {
                continue;
            }

            $rows = [];

            foreach ($breakdown->taxRows as $row) {
                if (TaxDirection::Additive === $row->direction) {
                    $rows[] = [BigDecimal::of($row->rate)->toScale(2, RoundingMode::HalfEven)->__toString(), $row->amount];
                }
            }

            $out[] = [$breakdown->supplyType === SupplyType::Goods ? 'TLB1' : 'TPS1', $breakdown->lineSubtotal, $rows];
        }

        return $out;
    }

    /**
     * @param array<string, array<string, array{taxable: BigDecimal, tax: BigDecimal}>> $categories
     */
    private function add(array &$categories, string $category, string $rate, BigDecimal $taxable, BigDecimal $tax): void
    {
        $categories[$category][$rate] ??= ['taxable' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
        $categories[$category][$rate]['taxable'] = $categories[$category][$rate]['taxable']->plus($taxable);
        $categories[$category][$rate]['tax'] = $categories[$category][$rate]['tax']->plus($tax);
    }

    /**
     * Minor units to a two-decimal amount.
     */
    private function major(BigDecimal $minor): string
    {
        return $minor->dividedBy(100, 2, RoundingMode::HalfEven)->__toString();
    }
}
