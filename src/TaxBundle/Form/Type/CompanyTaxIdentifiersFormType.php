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

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * @extends AbstractType<array{identifiers: mixed}>
 */
final class CompanyTaxIdentifiersFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // SIRET, SIREN and VAT number in fields of their own, the rest in the
        // list — see TaxIdentifierType::PROMINENT_LABELS.
        $builder->add('siret', TextType::class, [
            'label' => 'tax.company_identifiers.siret',
            'required' => false,
            'attr' => ['inputmode' => 'numeric', 'maxlength' => 14],
        ]);
        $builder->add('siren', TextType::class, [
            'label' => 'tax.company_identifiers.siren',
            'required' => false,
            'attr' => ['inputmode' => 'numeric', 'maxlength' => 9],
        ]);
        $builder->add('vatNumber', TextType::class, [
            'label' => 'tax.company_identifiers.vat_number',
            'required' => false,
        ]);

        $builder->add('identifiers', LiveCollectionType::class, [
            'entry_type' => TaxIdentifierType::class,
            'entry_options' => ['labels' => TaxIdentifierType::OTHER_LABELS],
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'required' => false,
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'company_tax_identifiers';
    }
}
