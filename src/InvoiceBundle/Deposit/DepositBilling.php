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

namespace Augias\InvoiceBundle\Deposit;

use Augias\CoreBundle\Entity\Discount;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\QuoteBundle\Entity\Line as QuoteLine;
use Augias\QuoteBundle\Entity\Quote;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Service\TaxSnapshotCopier;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;
use function array_map;
use function array_values;
use function count;
use function implode;
use function rtrim;
use function sort;
use function str_replace;

/**
 * Billing a quote in stages: a deposit (or several) ahead of the work, then
 * the final invoice for what is left.
 *
 * A deposit invoice is an invoice like any other — numbered, with its tax,
 * paid and booked the usual way — whose lines say "deposit of 30 % on quote
 * n° 12", one line per tax treatment of the quote so that each rate is billed
 * its share. The final invoice carries the quote's lines in full, then one
 * negative line per line of every deposit invoice issued, so its totals — net,
 * tax, amount due — are what is left to bill. Every figure the books, the
 * reports and the e-invoice read is therefore right without knowing what a
 * deposit is: each document bears the tax on what it actually bills.
 *
 * Disbursements are left out of a deposit: they are passed on at cost when
 * they are incurred, not billed ahead.
 *
 * @see \Augias\InvoiceBundle\Tests\Deposit\DepositBillingTest
 */
final readonly class DepositBilling
{
    public function __construct(
        private InvoiceManager $invoiceManager,
        private TaxSnapshotCopier $taxSnapshotCopier,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The share of the quote, in percent, that no deposit invoice has claimed
     * yet. Drafts count: two deposit forms open at once must not add up to
     * more than the quote.
     */
    #[AsTwigFunction('deposit_remaining_percentage')]
    public function remainingPercentage(Quote $quote): BigDecimal
    {
        $base = BigDecimal::zero();

        foreach ($this->billableLines($quote) as $line) {
            $base = $base->plus($this->net($line));
        }

        if (! $base->isPositive()) {
            return BigDecimal::zero();
        }

        $billed = BigDecimal::zero();

        foreach ($quote->getDepositInvoices() as $deposit) {
            if (InvoiceStatus::Cancelled === $deposit->getStatus()) {
                continue;
            }

            foreach ($deposit->getLines() as $line) {
                if (! $line->isNote()) {
                    $billed = $billed->plus($this->net($line));
                }
            }
        }

        $remaining = BigDecimal::of(100)->minus($billed->multipliedBy(100)->dividedBy($base, 2, RoundingMode::HalfUp));

        return $remaining->isNegative() ? BigDecimal::zero() : $remaining;
    }

    /**
     * A deposit invoice for the given share of the quote, not saved: the
     * invoice form shows it for checking, like the invoice made from a quote.
     */
    public function draft(Quote $quote, BigDecimal $percentage): Invoice
    {
        // Client, contacts, terms, discount and invoice-level taxes come over as
        // for the final invoice; only the lines differ.
        $invoice = $this->invoiceManager->draftFromQuote($quote);

        foreach ($invoice->getLines()->toArray() as $line) {
            $invoice->removeLine($line);
        }

        $share = $percentage->dividedBy(100, 6, RoundingMode::HalfUp);
        $groups = $this->groups($quote);
        $position = 0;

        foreach ($groups as $group) {
            $amount = BigDecimal::zero();

            foreach ($group as $line) {
                $amount = $amount->plus($this->net($line));
            }

            $first = $group[0];
            $taxes = $this->taxNames($first);

            $deposit = new Line();
            $deposit->setDescription($this->translator->trans(
                count($groups) > 1 && '' !== $taxes ? 'invoice.deposit.line_with_tax' : 'invoice.deposit.line',
                ['%percentage%' => $this->percentageLabel($percentage), '%quote%' => $quote->getQuoteId(), '%tax%' => $taxes],
            ));
            $deposit->setPrice(BigInteger::of((string) $amount->multipliedBy($share)->toScale(0, RoundingMode::HalfUp)));
            $deposit->setQty(1);
            $deposit->setSupplyType($first->getSupplyType());
            $deposit->setPosition($position++);
            $deposit->getTaxes()->clear();

            foreach ($first->getTaxes() as $tax) {
                $deposit->addTax($this->taxSnapshotCopier->copyLineTax($tax));
            }

            $invoice->addLine($deposit);
        }

        // A discount in percent applies to the deposit as it does to the quote; a
        // discount of a fixed amount is taken in the same proportion as the rest.
        $discount = $quote->getDiscount();

        if (Discount::TYPE_MONEY === $discount->getType()) {
            $sharedDiscount = new Discount();
            $sharedDiscount->setType(Discount::TYPE_MONEY);
            $sharedDiscount->setValueMoney(BigInteger::of((string) $discount->getValueMoney()->toBigDecimal()->multipliedBy($share)->toScale(0, RoundingMode::HalfUp)));
            $invoice->setDiscount($sharedDiscount);
        }

        $invoice->setSubject($this->translator->trans('invoice.deposit.subject', ['%quote%' => $quote->getQuoteId()]));
        $invoice->setDepositForQuote($quote);

        return $invoice;
    }

    /**
     * Takes the deposits already billed off the final invoice of the quote:
     * one negative line per line of each deposit invoice issued, with the
     * same tax, so that what was billed ahead is not billed twice.
     */
    public function deduct(Quote $quote, Invoice $final): void
    {
        $position = 0;

        foreach ($final->getLines() as $line) {
            $position = $line->getPosition() >= $position ? $line->getPosition() + 1 : $position;
        }

        $fixedDiscount = BigDecimal::zero();

        foreach ($this->issued($quote) as $deposit) {
            foreach ($deposit->getLines() as $line) {
                if ($line->isNote()) {
                    continue;
                }

                $deduction = new Line();
                $deduction->setDescription($this->translator->trans('invoice.deposit.deduction', [
                    '%invoice%' => $deposit->getInvoiceId(),
                    '%date%' => $deposit->getInvoiceDate()->format('d/m/Y'),
                ]));
                $deduction->setPrice($line->getPrice());
                $deduction->setQty($line->getQty()->toBigDecimal()->negated());
                $deduction->setUnit($line->getUnit());
                $deduction->setSupplyType($line->getSupplyType());
                $deduction->setPosition($position++);
                $deduction->getTaxes()->clear();

                foreach ($line->getTaxes() as $tax) {
                    $deduction->addTax($this->taxSnapshotCopier->copyLineTax($tax));
                }

                $final->addLine($deduction);
            }

            if (Discount::TYPE_MONEY === $deposit->getDiscount()->getType()) {
                $fixedDiscount = $fixedDiscount->plus($deposit->getDiscount()->getValueMoney()->toBigDecimal());
            }
        }

        // The part of a fixed discount the deposits already gave is not given again.
        $discount = $final->getDiscount();

        if (Discount::TYPE_MONEY === $discount->getType() && $fixedDiscount->isPositive()) {
            $left = $discount->getValueMoney()->toBigDecimal()->minus($fixedDiscount);
            $rest = new Discount();
            $rest->setType(Discount::TYPE_MONEY);
            $rest->setValueMoney(BigInteger::of((string) ($left->isNegative() ? BigDecimal::zero() : $left)->toScale(0, RoundingMode::HalfUp)));
            $final->setDiscount($rest);
        }
    }

    /**
     * The deposit invoices that count: issued (they have a number) and not cancelled.
     *
     * @return list<Invoice>
     */
    public function issued(Quote $quote): array
    {
        $issued = [];

        foreach ($quote->getDepositInvoices() as $deposit) {
            if ($deposit->isNumbered() && InvoiceStatus::Cancelled !== $deposit->getStatus()) {
                $issued[] = $deposit;
            }
        }

        return $issued;
    }

    /**
     * @return list<QuoteLine>
     */
    private function billableLines(Quote $quote): array
    {
        $lines = [];

        foreach ($quote->getLines() as $line) {
            if (! $line->isNote() && ! $line->isDisbursement()) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The quote's lines by tax treatment: goods apart from services, and one
     * group per set of taxes.
     *
     * @return list<non-empty-list<QuoteLine>>
     */
    private function groups(Quote $quote): array
    {
        $groups = [];

        foreach ($this->billableLines($quote) as $line) {
            $taxes = array_map(
                static fn (LineTax $tax): string => implode('|', [$tax->getNameSnapshot(), $tax->getRateSnapshot(), $tax->getTypeSnapshot()->value, $tax->getCategorySnapshot()->value, (int) $tax->isCompound()]),
                $line->getTaxes()->toArray(),
            );
            sort($taxes);

            $groups[$line->getSupplyType()->value . '#' . implode('+', $taxes)][] = $line;
        }

        return array_values($groups);
    }

    private function taxNames(QuoteLine $line): string
    {
        return implode(', ', array_map(
            static fn (LineTax $tax): string => (string) $tax->getNameSnapshot(),
            $line->getTaxes()->toArray(),
        ));
    }

    /**
     * Price times quantity, in minor units.
     */
    private function net(Line | QuoteLine $line): BigDecimal
    {
        return $line->getPrice()->toBigDecimal()->multipliedBy($line->getQty()->toBigDecimal());
    }

    /**
     * "30", "33,33": no trailing zeros, a decimal comma.
     */
    private function percentageLabel(BigDecimal $percentage): string
    {
        $label = (string) $percentage->toScale(2, RoundingMode::HalfUp);
        $label = rtrim(rtrim($label, '0'), '.');

        return str_replace('.', ',', $label);
    }
}
