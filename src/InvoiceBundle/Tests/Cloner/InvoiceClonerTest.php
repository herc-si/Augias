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

namespace Augias\InvoiceBundle\Tests\Cloner;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Discount;
use Augias\CronBundle\Enum\ScheduleEndType;
use Augias\CronBundle\Enum\ScheduleRecurringType;
use Augias\InvoiceBundle\Cloner\InvoiceCloner;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Entity\RecurringInvoiceLine;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\Tax;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use DateTimeImmutable;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\TestCase;

final class InvoiceClonerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @throws MathException
     */
    public function testClone(): void
    {
        $client = new Client();
        $client->setName('Test Client');
        $client->setWebsite('https://example.com');
        $client->setCreated(Carbon::parse('NOW'));

        $tax = new Tax();
        $tax->setName('VAT');
        $tax->setRate(14.00);
        $tax->setType(Tax::TYPE_INCLUSIVE);

        $line = new Line();
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $line->addTax($lineTax);
        $line->setDescription('Line Description');
        $line->setCreated(Carbon::now());
        $line->setPrice(120);
        $line->setQty(10);
        $line->setTotal(120 * 10);

        $invoice = new Invoice();
        $invoice->setBaseTotal(123);

        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(12);

        $invoice->setDiscount($discount);
        $invoice->setNotes('Notes');
        $invoice->setTax(432);
        $invoice->setTerms('Terms');
        $invoice->setTotal(987);
        $invoice->setClient($client);
        $invoice->addLine($line);

        $invoiceManager = M::mock(InvoiceManager::class);
        $invoiceManager->shouldReceive('create');

        $invoiceCloner = new InvoiceCloner($invoiceManager);

        $newInvoice = $invoiceCloner->clone($invoice);

        self::assertInstanceOf(Invoice::class, $newInvoice);

        self::assertEquals($invoice->getTotal(), $newInvoice->getTotal());
        self::assertEquals($invoice->getBaseTotal(), $newInvoice->getBaseTotal());
        self::assertSame($invoice->getDiscount(), $newInvoice->getDiscount());
        self::assertSame($invoice->getNotes(), $newInvoice->getNotes());
        self::assertSame($invoice->getTerms(), $newInvoice->getTerms());
        self::assertEquals($invoice->getTax(), $newInvoice->getTax());
        self::assertSame($client, $newInvoice->getClient());
        self::assertNull($newInvoice->getStatus());

        self::assertNotSame($invoice->getUuid(), $newInvoice->getUuid());
        self::assertNull($newInvoice->getId());
        // A copy is a draft: it takes its number when it is finalised.
        self::assertSame('', $newInvoice->getInvoiceId());

        self::assertCount(1, $newInvoice->getLines());

        $invoiceLine = $newInvoice->getLines();
        self::assertInstanceOf(Line::class, $invoiceLine[0]);

        self::assertCount(1, $invoiceLine[0]->getTaxes());
        self::assertSame('VAT', $invoiceLine[0]->getTaxes()->first()->getNameSnapshot());
        self::assertSame($line->getDescription(), $invoiceLine[0]->getDescription());
        self::assertInstanceOf(DateTimeImmutable::class, $invoiceLine[0]->getCreated());
        self::assertEquals($line->getPrice(), $invoiceLine[0]->getPrice());
        self::assertTrue($line->getQty()->isEqualTo($invoiceLine[0]->getQty()));
    }

    public function testCloneWithRecurring(): void
    {
        $date = Carbon::now();

        $client = new Client();
        $client->setName('Test Client');
        $client->setWebsite('http://example.com');
        $client->setCreated(Carbon::parse('NOW'));

        $tax = new Tax();
        $tax->setName('VAT');
        $tax->setRate(14.00);
        $tax->setType(Tax::TYPE_INCLUSIVE);

        $line = new RecurringInvoiceLine();
        $lineTax = new LineTax();
        $lineTax->snapshotFrom($tax);

        $line->addTax($lineTax);
        $line->setDescription('Line Description');
        $line->setCreated(Carbon::now());
        $line->setPrice(120);
        $line->setQty(10);
        $line->setTotal(120 * 10);

        $invoice = new RecurringInvoice();
        $invoice->setBaseTotal(123);

        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(12);

        $invoice->setDiscount($discount);
        $invoice->setNotes('Notes');
        $invoice->setTax(432);
        $invoice->setTerms('Terms');
        $invoice->setTotal(987);
        $invoice->setClient($client);
        $invoice->addLine($line);
        $invoice->setDateStart($date);
        $invoice->getRecurringOptions()->setType(ScheduleRecurringType::WEEKLY);
        $invoice->getRecurringOptions()->setEndType(ScheduleEndType::AFTER);
        $invoice->getRecurringOptions()->setEndOccurrence(1);

        $invoiceManager = M::mock(InvoiceManager::class);
        $invoiceManager->shouldReceive('create');

        $invoiceCloner = new InvoiceCloner($invoiceManager);

        /** @var RecurringInvoice $newInvoice */
        $newInvoice = $invoiceCloner->clone($invoice);

        self::assertEquals($invoice->getTotal(), $newInvoice->getTotal());
        self::assertEquals($invoice->getBaseTotal(), $newInvoice->getBaseTotal());
        self::assertSame($invoice->getDiscount(), $newInvoice->getDiscount());
        self::assertSame($invoice->getNotes(), $newInvoice->getNotes());
        self::assertSame($invoice->getTerms(), $newInvoice->getTerms());
        self::assertEquals($invoice->getTax(), $newInvoice->getTax());
        self::assertSame($client, $newInvoice->getClient());
        self::assertNull($newInvoice->getStatus());

        self::assertNull($newInvoice->getId());

        self::assertCount(1, $newInvoice->getLines());

        $invoiceLine = $newInvoice->getLines();
        self::assertInstanceOf(Line::class, $invoiceLine[0]);

        self::assertCount(1, $invoiceLine[0]->getTaxes());
        self::assertSame('VAT', $invoiceLine[0]->getTaxes()->first()->getNameSnapshot());
        self::assertSame($line->getDescription(), $invoiceLine[0]->getDescription());
        self::assertInstanceOf(DateTimeImmutable::class, $invoiceLine[0]->getCreated());
        self::assertEquals($line->getPrice(), $invoiceLine[0]->getPrice());
        self::assertTrue($line->getQty()->isEqualTo($invoiceLine[0]->getQty()));
        self::assertSame($newInvoice->getDateStart(), $invoice->getDateStart());
        self::assertSame($newInvoice->getDateEnd(), $invoice->getDateEnd());
    }
}
