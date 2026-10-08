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

namespace Augias\CatalogBundle\Tests\Form\Type;

use Augias\CatalogBundle\Entity\Product;
use Augias\CatalogBundle\Enum\ProductType;
use Augias\CatalogBundle\Form\Type\ProductFormType;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\CoreBundle\Tests\FormTestCase;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;
use Money\Currency;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use function array_map;

/**
 * @see \Augias\CatalogBundle\Form\Type\ProductFormType
 */
final class ProductFormTypeTest extends FormTestCase
{
    use EnsureApplicationInstalled;

    /**
     * The money field hands back a BigDecimal (MoneyBundle's ViewTransformer),
     * and the entity used to declare its prices as BigInteger — so every save
     * of this form died on "Expected argument of type ?Brick\Math\BigInteger,
     * Brick\Math\BigDecimal given" before Doctrine was ever reached. The prices
     * are BigNumber now, which is what BillBundle already did.
     */
    public function testSubmit(): void
    {
        $product = $this->submit(['salePrice' => '700', 'purchasePrice' => '250']);

        // Minor units: 700 EUR entered, 70000 cents stored.
        self::assertSame('70000', (string) $product->getSalePrice());
        self::assertSame('25000', (string) $product->getPurchasePrice());
    }

    /**
     * Pins down current behaviour rather than endorsing it. Product::$purchasePrice
     * documents null as "isn't bought in", and getMargin() promises null when
     * there is nothing to compare — but the shared money transformer turns an
     * empty input into zero, not null, so a service submitted with no purchase
     * price reports its full sale price as margin. Changing that means touching
     * MoneyBundle's ViewTransformer, which every money field in the application
     * shares, so it is recorded here rather than fixed in passing.
     */
    public function testAnEmptyPurchasePriceBecomesZeroRatherThanNull(): void
    {
        $product = $this->submit(['salePrice' => '700', 'purchasePrice' => '']);

        self::assertSame('70000', (string) $product->getSalePrice());
        self::assertSame('0', (string) $product->getPurchasePrice());
        self::assertSame('70000', (string) $product->getMargin());
    }

    /**
     * The margin compares a value that may have come straight off the form with
     * one read back from the database, so it has to cope with a BigDecimal and
     * a BigInteger meeting.
     */
    public function testMarginIsComputedFromSubmittedPrices(): void
    {
        $product = $this->submit(['salePrice' => '120', 'purchasePrice' => '90']);

        self::assertSame('3000', (string) $product->getMargin());
    }

    /**
     * The catalogue side of the shared category list — a purchase-only category
     * must not be offered here.
     */
    public function testOnlyCatalogueCategoriesAreOffered(): void
    {
        $this->persistCategory('Prestations', purchases: false, catalog: true);
        $this->persistCategory('Frais bancaires', purchases: true, catalog: false);

        $view = $this->form()->createView();
        $names = array_map(
            static fn (object $choice): string => (string) $choice->label,
            $view->children['category']->vars['choices'],
        );

        self::assertContains('Prestations', $names);
        self::assertNotContains('Frais bancaires', $names);
    }

    /**
     * The catalogue form used to default its currency to a hardcoded EUR,
     * because neither the Add nor the Edit action passes one. For a company
     * trading in a currency with a different number of decimals, the money
     * field then scales by the wrong factor.
     *
     * JPY is the case that hurts: it has no minor unit at all, so a hardcoded
     * EUR turns ¥700 into 70 000 stored — the same order-of-magnitude error
     * that the catalogue picker had, arriving by a different route. The default
     * now follows the company's own currency.
     */
    public function testPricesFollowTheCompanyCurrencyRatherThanAHardcodedEuro(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::CURRENCY_CONFIG_PATH, 'JPY');

        // Deliberately no `currency` option — this is what the Add and Edit
        // actions do, and the default is what is under test.
        $form = $this->factory->create(ProductFormType::class, new Product());

        $form->submit([
            'name' => 'Journée de développement',
            'type' => ProductType::Service->value,
            'unit' => QuantityUnit::Day->value,
            'salePrice' => '700',
            'active' => '1',
        ], false);

        self::assertTrue($form->isSynchronized());

        $product = $form->getData();
        self::assertInstanceOf(Product::class, $product);

        // 700 yen is 700 minor units, not 70 000.
        self::assertSame('700', (string) $product->getSalePrice());
    }

    /**
     * The same default seen from the other side: the field is told to render
     * the company's currency, not EUR.
     */
    public function testTheCurrencyDefaultIsTheCompanyCurrency(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::CURRENCY_CONFIG_PATH, 'JPY');

        $form = $this->factory->create(ProductFormType::class, new Product());

        self::assertSame(
            'JPY',
            $form->get('salePrice')->getConfig()->getOption('currency')->getCode(),
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function submit(array $overrides): Product
    {
        $form = $this->form();

        $form->submit([
            'name' => 'Journée de développement',
            'reference' => 'DEV-JOUR',
            'description' => '',
            'type' => ProductType::Service->value,
            'unit' => QuantityUnit::Day->value,
            'salePrice' => '700',
            'purchasePrice' => '250',
            'active' => '1',
            ...$overrides,
        ], false);

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->get('salePrice')->getErrors(true));

        $product = $form->getData();
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    /**
     * @return FormInterface<Product>
     */
    private function form(): FormInterface
    {
        return $this->factory->create(
            ProductFormType::class,
            new Product(),
            ['currency' => new Currency('EUR')],
        );
    }

    private function persistCategory(string $name, bool $purchases, bool $catalog): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $category = new Category()
            ->setName($name)
            ->setUsedForPurchases($purchases)
            ->setUsedForCatalog($catalog);
        $category->setCompany($this->company);

        $entityManager->persist($category);
        $entityManager->flush();
    }

    /**
     * ProductFormType takes SystemConfig now (for the currency default), so the bare form
     * factory used here can no longer build it from its class name alone.
     *
     * @return list<FormTypeInterface<Product>>
     */
    protected function getTypes(): array
    {
        return [...parent::getTypes(), new ProductFormType(
            self::getContainer()->get(SystemConfig::class),
            self::getContainer()->get(CategoryRepository::class),
        )];
    }
}
