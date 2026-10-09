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

use Augias\TaxBundle\Entity\InvoiceTax;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxDirection;
use Augias\TaxBundle\Model\TaxChoiceLabel;
use Override;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractType<InvoiceTax>
 */
final class InvoiceTaxType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tax', EntityType::class, [
                'label' => 'form.field.tax',
                'class' => Tax::class,
                'placeholder' => 'tax.invoice_tax.tax.placeholder',
                'required' => true,
                'choice_label' => static fn (Tax $tax): TranslatableMessage => TaxChoiceLabel::for($tax),
                // EntityType does not translate its choices unless told to.
                'choice_translation_domain' => 'messages',
                'attr' => [
                    'class' => 'invoice-tax-select',
                    'data-invoice-tax-target' => 'tax',
                ],
            ])
            ->add('direction', EnumType::class, [
                'label' => 'form.field.direction',
                'class' => TaxDirection::class,
                'required' => true,
                'placeholder' => false,
                'empty_data' => TaxDirection::Additive->value,
                'choice_label' => static fn (TaxDirection $direction): string => $direction->translationKey(),
                'attr' => [
                    'data-invoice-tax-target' => 'direction',
                ],
            ])
            ->add('note', TextType::class, [
                'required' => false,
                'label' => 'tax.invoice_tax.note.label',
                'attr' => [
                    'data-invoice-tax-target' => 'note',
                    'placeholder' => 'tax.invoice_tax.note.placeholder',
                ],
            ])
            ->add('sequence', HiddenType::class, [
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'data-invoice-tax-target' => 'sequence',
                ],
            ]);

        // Populate snapshot fields (name/rate/category) from the selected Tax
        // entity so newly-bound InvoiceTax rows pass NotBlank validation and
        // persist with the rate frozen at the time of selection.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $invoiceTax = $event->getData();
            if (! $invoiceTax instanceof InvoiceTax) {
                return;
            }

            $tax = $invoiceTax->getTax();
            if (! $tax instanceof Tax) {
                return;
            }

            if ($invoiceTax->getNameSnapshot() !== null && $invoiceTax->getNameSnapshot() !== '') {
                return;
            }

            $invoiceTax->snapshotFrom($tax);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoiceTax::class,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'invoice_tax';
    }
}
