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

namespace Augias\CoreBundle\Form\Type;

use Augias\CoreBundle\Entity\Company;
use Augias\MoneyBundle\Currency\CurrencyPolicy;
use Augias\MoneyBundle\Form\Type\CurrencyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Company>
 */
final class CompanyType extends AbstractType
{
    public function __construct(
        private readonly CurrencyPolicy $currencies,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, ['label' => 'form.field.name'])
            ->add(
                'currency',
                CurrencyType::class,
                [
                'label' => 'form.field.currency',
                    'placeholder' => 'form.placeholder.choose_currency',
                ]
            );

        // A new company starts in the deployment's currency rather than on
        // an empty choice.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $company = $event->getData();

            if (! $company instanceof Company) {
                $company = new Company();
                $event->setData($company);
            }

            if ($company->currency === null || $company->currency === '') {
                $company->currency = $this->currencies->defaultCode();
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
        ]);
    }
}
