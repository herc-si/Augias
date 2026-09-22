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

namespace Augias\InvoiceBundle\Cloner;

use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Entity\RecurringInvoiceLine;
use Augias\InvoiceBundle\Entity\RecurringOptions;
use Augias\InvoiceBundle\Exception\InvalidTransitionException;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\TaxBundle\Service\TaxSnapshotCopier;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Traversable;

/**
 * @see \Augias\InvoiceBundle\Tests\Cloner\InvoiceClonerTest
 */
final readonly class InvoiceCloner
{
    public function __construct(
        private InvoiceManager $invoiceManager,
        private BillingIdGenerator $billingIdGenerator,
        private TaxSnapshotCopier $taxSnapshotCopier = new TaxSnapshotCopier(),
    ) {
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws JsonException
     * @throws InvalidTransitionException
     * @throws MathException
     */
    public function clone(Invoice | RecurringInvoice $invoice): Invoice | RecurringInvoice
    {
        // We don't use 'clone', since cloning an invoice will clone all the item id's and nested values.
        // Rather set it manually
        $class = $invoice::class;
        /** @var RecurringInvoice|Invoice $newInvoice */
        $newInvoice = new $class();

        $now = Carbon::now();

        $newInvoice->setCreated($now);
        $newInvoice->setClient($invoice->getClient());
        $newInvoice->setBaseTotal($invoice->getBaseTotal());
        $newInvoice->setDiscount($invoice->getDiscount());
        $newInvoice->setNotes($invoice->getNotes());
        $newInvoice->setTotal($invoice->getTotal());
        $newInvoice->setTerms($invoice->getTerms());

        foreach ($invoice->getUsers() as $user) {
            $newInvoice->addUser($user);
        }

        if (\method_exists($newInvoice, 'setBalance')) {
            $newInvoice->setBalance($newInvoice->getTotal());
        }

        if ($invoice instanceof RecurringInvoice) {
            $newInvoice->setDateStart($invoice->getDateStart());
            $newInvoice->setDateEnd($invoice->getDateEnd());

            if ($invoice->getRecurringOptions() instanceof RecurringOptions) {
                $recurringOptions = new RecurringOptions();
                $recurringOptions->setType($invoice->getRecurringOptions()->getType());
                $recurringOptions->setDays($invoice->getRecurringOptions()->getDays());
                $recurringOptions->setEndDate($invoice->getRecurringOptions()->getEndDate());
                $recurringOptions->setEndOccurrence($invoice->getRecurringOptions()->getEndOccurrence());
                $recurringOptions->setEndType($invoice->getRecurringOptions()->getEndType());
                $newInvoice->setRecurringOptions($recurringOptions);
            }
        } else {
            $newInvoice->setDue($invoice->getDue());
            $newInvoice->setInvoiceId($this->billingIdGenerator->generate($newInvoice, ['field' => 'invoiceId']));
        }

        $newInvoice->setTax($invoice->getTax());

        array_map(static fn (Line $item): Invoice | RecurringInvoice => $newInvoice->addLine($item), iterator_to_array($this->addLine($invoice, $now)));

        if ($newInvoice instanceof Invoice) {
            foreach ($invoice->getInvoiceTaxes() as $sourceInvoiceTax) {
                $newInvoice->addInvoiceTax($this->taxSnapshotCopier->copyInvoiceTax($sourceInvoiceTax));
            }

            $this->invoiceManager->create($newInvoice);
        }

        return $newInvoice;
    }

    /**
     * @return Traversable<Line|RecurringInvoiceLine>
     * @throws MathException
     */
    private function addLine(Invoice | RecurringInvoice $invoice, Carbon $now): Traversable
    {
        foreach ($invoice->getLines() as $line) {
            if ($invoice instanceof RecurringInvoice) {
                $invoiceLine = new RecurringInvoiceLine();
            } else {
                $invoiceLine = new Line();
            }

            $invoiceLine->setCreated($now);
            $invoiceLine->setTotal($line->getTotal());
            $invoiceLine->setDescription($line->getDescription());
            $invoiceLine->setPrice($line->getPrice());
            $invoiceLine->setQty($line->getQty());
            // Carried over with the line: what was advanced in the client's
            // name on the original is still advanced in their name on the copy,
            // and a clone that quietly turned it into turnover would be the
            // easiest way to lose the distinction.
            $invoiceLine->setDisbursement($line->isDisbursement());

            $invoiceLine->getTaxes()->clear();
            foreach ($line->getTaxes() as $sourceLineTax) {
                $invoiceLine->addTax($this->taxSnapshotCopier->copyLineTax($sourceLineTax));
            }

            yield $invoiceLine;
        }
    }
}
