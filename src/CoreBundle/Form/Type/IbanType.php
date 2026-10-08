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
use Symfony\Component\Validator\Constraints\Iban;
use function implode;
use function is_string;
use function str_split;

/**
 * An IBAN, checked (country, length, check digits) and kept as it is printed
 * on a RIB: upper-cased, grouped by four — however it was pasted.
 *
 * @extends AbstractType<string>
 */
final class IbanType extends AbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (is_string($data)) {
                $iban = BankDetails::compact($data);
                $event->setData('' === $iban ? '' : implode(' ', str_split($iban, 4)));
            }
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Iban()],
            'attr' => ['placeholder' => 'FR76 3000 6000 0112 3456 7890 189', 'maxlength' => 42, 'autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
    }

    #[Override]
    public function getParent(): string
    {
        return TextType::class;
    }
}
