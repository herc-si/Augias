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

namespace Augias\QuoteBundle\Cloner;

use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\QuoteBundle\Entity\Line;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Model\Graph;
use Augias\TaxBundle\Service\TaxSnapshotCopier;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Traversable;

/**
 * @see \Augias\QuoteBundle\Tests\Cloner\QuoteClonerTest
 */
final readonly class QuoteCloner
{
    public function __construct(
        private WorkflowInterface $quoteStateMachine,
        private BillingIdGenerator $billingIdGenerator,
        private TaxSnapshotCopier $taxSnapshotCopier = new TaxSnapshotCopier(),
    ) {
    }

    /**
     * @throws MathException
     * @throws ContainerExceptionInterface
     */
    public function clone(Quote $quote): Quote
    {
        // We don't use 'clone', since cloning a quote will clone all the line id's and nested values.
        // We rather set it manually
        $newQuote = new Quote();

        $now = Carbon::now();

        $newQuote->setCreated($now);
        $newQuote->setClient($quote->getClient());
        $newQuote->setBaseTotal($quote->getBaseTotal());
        $newQuote->setDiscount($quote->getDiscount());
        $newQuote->setNotes($quote->getNotes());
        $newQuote->setTotal($quote->getTotal());
        $newQuote->setTerms($quote->getTerms());
        $newQuote->setQuoteId($this->billingIdGenerator->generate($newQuote, ['field' => 'quoteId']));

        foreach ($quote->getUsers() as $user) {
            $newQuote->addUser($user);
        }

        $newQuote->setTax($quote->getTax());

        array_map($newQuote->addLine(...), iterator_to_array($this->addLines($quote, $now)));

        foreach ($quote->getInvoiceTaxes() as $sourceInvoiceTax) {
            $newQuote->addInvoiceTax($this->taxSnapshotCopier->copyInvoiceTax($sourceInvoiceTax));
        }

        $this->quoteStateMachine->apply($newQuote, Graph::TRANSITION_NEW);

        return $newQuote;
    }

    /**
     * @throws MathException
     * @return Traversable<Line>
     */
    private function addLines(Quote $quote, Carbon $now): Traversable
    {
        foreach ($quote->getLines() as $line) {
            $quoteLine = new Line();
            $quoteLine->setCreated($now);
            $quoteLine->setTotal($line->getTotal());
            $quoteLine->setDescription($line->getDescription());
            $quoteLine->setPrice($line->getPrice());
            $quoteLine->setQty($line->getQty());
            $quoteLine->setSupplyType($line->getSupplyType());

            $quoteLine->getTaxes()->clear();
            foreach ($line->getTaxes() as $sourceLineTax) {
                $quoteLine->addTax($this->taxSnapshotCopier->copyLineTax($sourceLineTax));
            }

            yield $quoteLine;
        }
    }
}
