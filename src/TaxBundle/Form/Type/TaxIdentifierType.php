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

use Augias\TaxBundle\Entity\TaxIdentifier;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TaxIdentifier>
 */
final class TaxIdentifierType extends AbstractType
{
    /**
     * @var list<string>
     */
    /**
     * "Adresse électronique" is where e-invoices — and the answers to them —
     * are delivered, when it is not simply the SIREN: an address with a
     * suffix, one per establishment or per department.
     */
    public const array PRESET_LABELS = ['SIRET', 'SIREN', 'TVA intracommunautaire', 'Adresse électronique', 'RCS', 'Code APE/NAF', 'Autre'];

    public const string SIRET = 'SIRET';

    public const string SIREN = 'SIREN';

    public const string VAT_NUMBER = 'TVA intracommunautaire';

    /**
     * The three identifiers every French business has, which the company and
     * client forms ask for in fields of their own rather than in the list —
     * where they had to be picked from a dropdown to be given at all.
     *
     * @var list<string>
     */
    public const array PROMINENT_LABELS = [self::SIRET, self::SIREN, self::VAT_NUMBER];

    /**
     * What is left for the list once those three have fields of their own.
     *
     * @var list<string>
     */
    public const array OTHER_LABELS = ['Adresse électronique', 'RCS', 'Code APE/NAF', 'Autre'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', ChoiceType::class, [
            'choices' => array_combine($options['labels'], $options['labels']),
            'required' => true,
            'empty_data' => $options['labels'][0],
            'placeholder' => false,
        ]);

        $builder->add('value', TextType::class, [
                'label' => 'form.field.value',
            'required' => true,
        ]);

        $builder->add('primary', CheckboxType::class, [
                'label' => 'form.field.primary',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TaxIdentifier::class,
            'labels' => self::PRESET_LABELS,
        ]);
        $resolver->setAllowedTypes('labels', 'string[]');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'tax_identifier';
    }
}
