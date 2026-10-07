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

namespace Augias\CatalogBundle\Form\Type;

use Augias\CatalogBundle\Entity\Product;
use Augias\CatalogBundle\Enum\ProductType;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\Tax;
use Money\Currency;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Product>
 */
final class ProductFormType extends AbstractType
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, ['label' => 'catalog.form.name.label'])
            ->add('reference', null, [
                'label' => 'catalog.form.reference.label',
                'help' => 'catalog.form.reference.help',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'catalog.form.description.label',
                'help' => 'catalog.form.description.help',
                'required' => false,
            ])
            ->add('type', EnumType::class, [
                'label' => 'catalog.form.type.label',
                'class' => ProductType::class,
                'choice_label' => static fn (ProductType $type): string => $type->getLabel(),
                'placeholder' => false,
            ])
            ->add('unit', EnumType::class, [
                'label' => 'catalog.form.unit.label',
                'class' => QuantityUnit::class,
                'choice_label' => static fn (QuantityUnit $unit): string => $unit->getLabel(),
                'placeholder' => false,
            ])
            ->add('salePrice', MoneyType::class, [
                'label' => 'catalog.form.sale_price.label',
                'currency' => $options['currency'],
            ])
            ->add('purchasePrice', MoneyType::class, [
                'label' => 'catalog.form.purchase_price.label',
                'help' => 'catalog.form.purchase_price.help',
                'currency' => $options['currency'],
                'required' => false,
            ])
            ->add('tax', EntityType::class, [
                'label' => 'catalog.form.tax.label',
                'help' => 'catalog.form.tax.help',
                'class' => Tax::class,
                'choice_label' => 'name',
                'placeholder' => 'catalog.form.tax.placeholder',
                'required' => false,
            ])
            ->add('category', EntityType::class, [
                'label' => 'catalog.form.category.label',
                'class' => Category::class,
                'choice_label' => 'name',
                'placeholder' => 'catalog.form.category.placeholder',
                'required' => false,
                // The catalogue side of the shared category list — see the
                // matching filter in BillType.
                'choices' => $this->categoryRepository->sortedForUsage(CategoryUsage::Catalog),
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'catalog.form.active.label',
                'help' => 'catalog.form.active.help',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
            // The company's currency, not a hardcoded EUR: the money field
            // scales by the currency's own decimal count, so a wrong one
            // silently misplaces the decimal point for JPY and BHD.
            'currency' => $this->systemConfig->getCurrency(),
        ]);

        $resolver->setAllowedTypes('currency', Currency::class);
    }
}
