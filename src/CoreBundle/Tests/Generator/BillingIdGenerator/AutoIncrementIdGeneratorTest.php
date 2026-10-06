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

namespace Augias\CoreBundle\Tests\Generator\BillingIdGenerator;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Generator\BillingIdGenerator\AutoIncrementIdGenerator;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\QuoteBundle\Entity\Quote;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(AutoIncrementIdGenerator::class)]
final class AutoIncrementIdGeneratorTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testItGeneratesTheSameIdWhenNotSavingAnyEntities(): void
    {
        $generator = new AutoIncrementIdGenerator(self::getContainer()->get('doctrine'));

        self::assertSame('1', $generator->generate(new Invoice(), ['field' => 'invoiceId']));
        self::assertSame('1', $generator->generate(new Invoice(), ['field' => 'invoiceId']));

        self::assertSame('1', $generator->generate(new Quote(), ['field' => 'quoteId']));
        self::assertSame('1', $generator->generate(new Quote(), ['field' => 'quoteId']));
    }

    public function testItIncrementsTheId(): void
    {
        $client = ClientFactory::new([]);

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => '1']);

        $generator = new AutoIncrementIdGenerator(self::getContainer()->get('doctrine'));

        self::assertSame('2', $generator->generate(new Invoice(), ['field' => 'invoiceId']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => '2']);

        self::assertSame('3', $generator->generate(new Invoice(), ['field' => 'invoiceId']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => '101']);

        self::assertSame('102', $generator->generate(new Invoice(), ['field' => 'invoiceId']));
        self::assertSame('1', $generator->generate(new Quote(), ['field' => 'quoteId']));
    }

    public function testItIncrementsTheIdWithPrefix(): void
    {
        $client = ClientFactory::new([]);

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'INV-1']);

        $generator = new AutoIncrementIdGenerator(self::getContainer()->get('doctrine'));

        self::assertSame('2', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'INV-', 'suffix' => '']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'INV-2']);

        self::assertSame('3', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'INV-', 'suffix' => '']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'INV-101']);

        self::assertSame('102', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'INV-', 'suffix' => '']));
    }

    public function testItIncrementsTheIdWithSuffix(): void
    {
        $client = ClientFactory::new([]);

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => '1-00']);

        $generator = new AutoIncrementIdGenerator(self::getContainer()->get('doctrine'));

        self::assertSame('2', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => '', 'suffix' => '-00']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => '2-00']);

        self::assertSame('3', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => '', 'suffix' => '-00']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => '101-00']);

        self::assertSame('102', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => '', 'suffix' => '-00']));
    }

    public function testItIncrementsTheIdWithPrefixAndSuffix(): void
    {
        $client = ClientFactory::new([]);

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'INV-1-00']);

        $generator = new AutoIncrementIdGenerator(self::getContainer()->get('doctrine'));

        self::assertSame('2', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'INV-', 'suffix' => '-00']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'INV-2-00']);

        self::assertSame('3', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'INV-', 'suffix' => '-00']));

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'INV-101-00']);

        self::assertSame('102', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'INV-', 'suffix' => '-00']));
    }

    /**
     * A number from before the prefix was set — shorter than it, or not a
     * number once it is cut off — says nothing about the next one. On
     * PostgreSQL it made creating a quote a 500: "negative substring length
     * not allowed", then "invalid input syntax for type numeric" (preprod,
     * 06/10/2026).
     */
    public function testANumberFromAnotherSchemeIsLeftOut(): void
    {
        $client = ClientFactory::new([]);

        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => '7']);
        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'FACTURE-ABC']);
        InvoiceFactory::createOne(['client' => $client, 'invoiceId' => 'DEV-2026-0004']);

        $generator = new AutoIncrementIdGenerator(self::getContainer()->get('doctrine'));

        self::assertSame('5', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'DEV-2026-', 'suffix' => '']));
    }

    public function testOnlyNumbersFromAnotherSchemeStartsAtOne(): void
    {
        InvoiceFactory::createOne(['client' => ClientFactory::new([]), 'invoiceId' => '7']);

        $generator = new AutoIncrementIdGenerator(self::getContainer()->get('doctrine'));

        self::assertSame('1', $generator->generate(new Invoice(), ['field' => 'invoiceId', 'prefix' => 'DEV-2026-', 'suffix' => '']));
    }
}
