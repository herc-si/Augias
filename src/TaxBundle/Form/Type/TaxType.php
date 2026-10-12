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

namespace Augias\TaxBundle\Form\Type;

use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @see \Augias\TaxBundle\Tests\Form\Type\TaxTypeTest
 * @extends AbstractType<Tax>
 */
class TaxType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', null, ['label' => 'tax.form.name.label', 'sanitize_html' => true, 'allow_single_quotes' => true]);
        $builder->add('rate', PercentType::class, ['label' => 'tax.form.rate.label', 'scale' => 2, 'type' => 'integer']);

        $builder->add(
            'type',
            ChoiceType::class,
            [
                'label' => 'tax.form.type.label',
                'choices' => array_map(ucwords(...), Tax::getTypes()),
                'help' => 'tax.rates.explanation',
                'help_html' => true,
                'placeholder' => 'tax.rates.type.select',
            ]
        );

        $builder->add(
            'category',
            EnumType::class,
            [
                'label' => 'tax.form.category.label',
                'class' => TaxCategory::class,
                'choice_label' => static fn (TaxCategory $c) => $c->labelKey(),
                'placeholder' => false,
            ]
        );

        $builder->add(
            'compound',
            CheckboxType::class,
            [
                'label' => 'tax.form.compound.label',
                'help' => 'tax.form.compound.help',
                'required' => false,
            ]
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Tax::class]);
    }
}
