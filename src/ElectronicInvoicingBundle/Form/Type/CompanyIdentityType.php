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

namespace Augias\ElectronicInvoicingBundle\Form\Type;

use Augias\ElectronicInvoicingBundle\Onboarding\CompanyIdentity;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use function is_string;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function trim;

/**
 * The first step of the electronic invoicing setup. "Look up" fills the form
 * from the State's register without saving; "Save" writes it.
 *
 * @extends AbstractType<CompanyIdentity>
 */
final class CompanyIdentityType extends AbstractType
{
    public const string LOOK_UP = 'lookUp';

    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $digits = static fn (?string $value): string => (string) preg_replace('/\s+/', '', (string) $value);

        $builder
            ->add('siren', TextType::class, [
                'label' => 'einvoicing.setup.identity.siren',
                'help' => 'einvoicing.setup.identity.siren_help',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'maxlength' => 11],
                'constraints' => [
                    new NotBlank(message: 'einvoicing.setup.identity.siren_required'),
                    new Callback(static function (?string $value, ExecutionContextInterface $context) use ($digits): void {
                        if ($value !== null && ! preg_match('/^\d{9}$/', $digits($value))) {
                            $context->addViolation('einvoicing.setup.identity.siren_invalid');
                        }
                    }),
                ],
            ])
            ->add(self::LOOK_UP, SubmitType::class, [
                'label' => 'einvoicing.setup.identity.look_up',
                'attr' => ['class' => 'btn btn-outline-primary', 'formnovalidate' => 'formnovalidate'],
                'validate' => false,
            ])
            ->add('siret', TextType::class, [
                'label' => 'einvoicing.setup.identity.siret',
                'help' => 'einvoicing.setup.identity.siret_help',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'maxlength' => 17],
                'constraints' => [
                    new NotBlank(message: 'einvoicing.setup.identity.siret_required'),
                    new Callback(static function (?string $value, ExecutionContextInterface $context) use ($digits): void {
                        $siren = $context->getRoot()->get('siren')->getData();
                        if ($value === null || ! preg_match('/^\d{14}$/', $digits($value))) {
                            $context->addViolation('einvoicing.setup.identity.siret_invalid');
                        } elseif (is_string($siren) && ! str_starts_with($digits($value), $digits($siren))) {
                            $context->addViolation('einvoicing.setup.identity.siret_mismatch');
                        }
                    }),
                ],
            ])
            ->add('vatExempt', ChoiceType::class, [
                'label' => 'einvoicing.setup.identity.vat_regime',
                'expanded' => true,
                // One is always ticked: "required" would only star each option.
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'einvoicing.setup.identity.vat_exempt' => true,
                    'einvoicing.setup.identity.vat_liable' => false,
                ],
            ])
            ->add('vatNumber', TextType::class, [
                'label' => 'einvoicing.setup.identity.vat_number',
                'help' => 'einvoicing.setup.identity.vat_number_help',
                'required' => false,
                'constraints' => [
                    new Callback(static function (?string $value, ExecutionContextInterface $context): void {
                        if ($context->getRoot()->get('vatExempt')->getData() !== true && trim((string) $value) === '') {
                            $context->addViolation('einvoicing.setup.identity.vat_number_required');
                        }
                    }),
                    new Regex(pattern: '/^\s*FR\s*\d{2}\s*\d{3}\s*\d{3}\s*\d{3}\s*$/i', message: 'einvoicing.setup.identity.vat_number_invalid'),
                ],
            ])
            ->add('street1', TextType::class, ['label' => 'einvoicing.setup.identity.street', 'required' => false])
            ->add('street2', TextType::class, ['label' => 'einvoicing.setup.identity.street2', 'required' => false])
            ->add('zip', TextType::class, [
                'label' => 'einvoicing.setup.identity.zip',
                'constraints' => [new NotBlank(message: 'einvoicing.setup.identity.zip_required')],
            ])
            ->add('city', TextType::class, [
                'label' => 'einvoicing.setup.identity.city',
                'constraints' => [new NotBlank(message: 'einvoicing.setup.identity.city_required')],
            ])
            ->add('registryName', HiddenType::class, ['required' => false])
            ->add('save', SubmitType::class, [
                'label' => 'einvoicing.setup.identity.save',
                'attr' => ['class' => 'btn btn-primary'],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompanyIdentity::class,
            'translation_domain' => 'messages',
        ]);
    }
}
