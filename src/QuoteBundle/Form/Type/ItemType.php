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

namespace Augias\QuoteBundle\Form\Type;

use Augias\CoreBundle\Form\Transformer\QuantityTransformer;
use Augias\CoreBundle\Form\Type\SupplyTypeType;
use Augias\QuoteBundle\Entity\Line;
use Augias\TaxBundle\Form\Type\LineTaxType;
use Augias\TaxBundle\Service\TaxAvailability;
use Money\Currency;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * @see \Augias\QuoteBundle\Tests\Form\Type\ItemTypeTest
 * @extends AbstractType<Line>
 */
class ItemType extends AbstractType
{
    public function __construct(
        private readonly TaxAvailability $taxAvailability
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'description',
            TextareaType::class,
            [
                'label' => 'form.field.description',
                'attr' => [
                    'class' => 'input-medium quote-item-name',
                ],
            ]
        );

        $builder->add(
            'price',
            MoneyType::class,
            [
                'label' => 'form.field.price',
                'attr' => [
                    'class' => 'input-small quote-item-price',
                ],
                'currency' => $options['currency'],
            ]
        );

        $builder->add(
            'qty',
            NumberType::class,
            [
                'label' => 'form.field.qty',
                'empty_data' => '1',
                'attr' => [
                    'class' => 'input-mini quote-item-qty',
                ],
            ]
        );

        // NumberType's own view transformer round-trips through a float, which would undo
        // the exact-decimal quantity the entity now holds.
        $builder->get('qty')
            ->resetViewTransformers()
            ->addViewTransformer(new QuantityTransformer());

        if ($this->taxAvailability->isOffered()) {
            // Only where tax is: goods and services differ by when their VAT
            // falls due, which is nothing to a company that charges none.
            $builder->add('supplyType', SupplyTypeType::class);

            $builder->add(
                'taxes',
                LiveCollectionType::class,
                [
                    'entry_type' => LineTaxType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
                    'required' => false,
                    'by_reference' => false,
                    'label' => false,
                    'attr' => [
                        'data-controller' => 'line-tax',
                    ],
                ]
            );
        }
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'quote_item';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', Line::class)
            ->setRequired('currency')
            ->setAllowedTypes('currency', [Currency::class]);
    }
}
