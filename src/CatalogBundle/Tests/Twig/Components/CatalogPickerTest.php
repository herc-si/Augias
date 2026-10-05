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

namespace Augias\CatalogBundle\Tests\Twig\Components;

use Augias\CatalogBundle\Entity\Product;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Twig\Components\CreateInvoice;
use Augias\QuoteBundle\DTO\QuoteFormDTO;
use Augias\QuoteBundle\Twig\Components\CreateQuote;
use Augias\TaxBundle\Entity\Tax;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The catalogue only pays for itself if picking an entry fills the line in
 * completely; a picker that inserted a blank row would be slower than typing.
 */
final class CatalogPickerTest extends LiveComponentTest
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function componentProvider(): iterable
    {
        yield 'invoice' => [CreateInvoice::class, InvoiceFormDTO::class];
        yield 'quote' => [CreateQuote::class, QuoteFormDTO::class];
    }

    /**
     * @param class-string                                $componentClass
     * @param class-string<InvoiceFormDTO|QuoteFormDTO> $dtoClass
     */
    #[DataProvider('componentProvider')]
    public function testPickingAnEntryAddsAFullyFilledLine(string $componentClass, string $dtoClass): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $tax = new Tax();
        $tax->setCompany($this->company)->setName('TVA 20')->setRate(20.0)->setType('Inclusive');
        $entityManager->persist($tax);

        $product = new Product();
        $product->setCompany($this->company)
            ->setName('Journée de conseil')
            ->setDescription('Accompagnement sur site')
            ->setSalePrice(BigInteger::of(90000))
            ->setTax($tax);
        $entityManager->persist($product);
        $entityManager->flush();

        // Lines wait for the client, whose currency and taxes they are priced in.
        $dto = new $dtoClass();
        $dto->client = ClientFactory::createOne(['name' => 'Acme Corp', 'currencyCode' => 'EUR']);

        $component = $this->createLiveComponent(name: $componentClass, data: ['dto' => $dto], client: $this->client)
            ->actingAs($this->getUser());

        $component->set('catalogProductId', (string) $product->getId())->call('addFromCatalog');

        $lines = $component->component()->formValues['lines'] ?? [];

        self::assertCount(1, $lines);

        $line = reset($lines);

        // Name and description are separate columns in the catalogue but a
        // single text field on the line, so they arrive joined.
        self::assertSame("Journée de conseil\nAccompagnement sur site", $line['description']);
        self::assertSame('1', $line['qty']);
        // 900 EUR, not 90 000. The catalogue stores minor units and the money
        // field reads major ones; this assertion used to expect the stored
        // integer, which locked in a line priced a hundred times over.
        // Compared numerically because the field re-renders the value through
        // its own formatting.
        self::assertSame(900.0, (float) $line['price']);
        self::assertCount(1, $line['taxes']);
    }

    public function testAnUnknownEntryAddsNothing(): void
    {
        $component = $this->createLiveComponent(name: CreateInvoice::class, data: [], client: $this->client)
            ->actingAs($this->getUser());

        $component->set('catalogProductId', '')->call('addFromCatalog');

        self::assertSame([], $component->component()->formValues['lines'] ?? []);
    }
}
