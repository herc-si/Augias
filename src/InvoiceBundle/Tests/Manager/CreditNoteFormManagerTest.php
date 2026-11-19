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

namespace Augias\InvoiceBundle\Tests\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Manager\CreditNoteFormManager;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreditNoteFormManager::class)]
final class CreditNoteFormManagerTest extends TestCase
{
    /**
     * A credit note cancelling an invoice takes back the VAT it charged: the
     * lines carry the invoice's taxes, at the rates the invoice was issued at.
     */
    public function testACancellationKeepsTheTaxesOfEachLine(): void
    {
        $vat = new Tax();
        $vat->setName('TVA');
        $vat->setRate(20.0);
        $vat->setType(Tax::TYPE_EXCLUSIVE);
        $vat->setCategory(TaxCategory::Standard);

        $lineTax = new LineTax();
        $lineTax->snapshotFrom($vat);

        $line = new Line();
        $line->setDescription('Audit');
        $line->setPrice(10000);
        $line->setQty(1);
        $line->addTax($lineTax);

        $invoice = new Invoice();
        $invoice->setClient(new Client());
        $invoice->addLine($line);

        // The rate changes after the invoice was issued: the credit note
        // still takes back what the invoice charged.
        $vat->setRate(10.0);

        $dto = new CreditNoteFormManager()->cancellationOf($invoice);

        $creditedLine = $dto->lines->first();
        self::assertInstanceOf(Line::class, $creditedLine);
        self::assertCount(1, $creditedLine->getTaxes());

        $creditedTax = $creditedLine->getTaxes()->first();
        self::assertInstanceOf(LineTax::class, $creditedTax);
        self::assertSame($vat, $creditedTax->getTax());
        self::assertSame('TVA', $creditedTax->getNameSnapshot());
        self::assertSame('20', rtrim(rtrim($creditedTax->getRateSnapshot(), '0'), '.'));
    }
}
