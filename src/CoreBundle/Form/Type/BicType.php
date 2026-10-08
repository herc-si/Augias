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

use Augias\CoreBundle\Company\BankDetails;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Bic;
use function is_string;

/**
 * A BIC (8 or 11 characters), checked and upper-cased.
 *
 * @extends AbstractType<string>
 */
final class BicType extends AbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (is_string($data)) {
                $event->setData(BankDetails::compact($data));
            }
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Bic()],
            'attr' => ['placeholder' => 'AGRIFRPP', 'maxlength' => 11, 'autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
    }

    #[Override]
    public function getParent(): string
    {
        return TextType::class;
    }
}
