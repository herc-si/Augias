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

namespace Augias\ElectronicInvoicingBundle\Form\Type\Provider;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use function is_array;
use function is_string;
use function trim;

/**
 * OAuth2 client_credentials for the SUPER PDP API (https://www.superpdp.tech)
 * — the company itself is enrolled on the platform outside Augias.
 *
 * @extends AbstractType<array{client_id: string, client_secret: string}>
 */
final class SuperPdpConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // In the default group: a `super_pdp` group was named here that no
        // form ever validated, so an empty secret went through unnoticed.
        $builder->add('client_id', TextType::class, [
            'label' => 'einvoicing.provider.super_pdp.client_id',
            'constraints' => new NotBlank(),
        ]);

        // Not mapped to the saved settings, and that is the whole point.
        //
        // The saved secret is never put into the form, so it is never rendered
        // — rendered, it also sat in clear in the live component's state, in
        // the page source. And what the user types survives the component
        // re-rendering on every change: a password field emptied on render
        // made the live component read back an empty value and save that,
        // which is how a typed secret never reached the database.
        //
        // Left empty, the saved secret stays — see SUBMIT below — so it is only
        // required when there is none yet.
        $builder->add('client_secret', PasswordType::class, [
            'label' => 'einvoicing.provider.super_pdp.client_secret',
            'help' => 'einvoicing.provider.super_pdp.client_secret_help',
            'mapped' => false,
            'always_empty' => false,
            'required' => false,
        ]);

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $settings = $event->getData();
            $settings = is_array($settings) ? $settings : [];
            $typed = trim((string) $event->getForm()->get('client_secret')->getData());

            if ('' !== $typed) {
                $settings['client_secret'] = $typed;
                $event->setData($settings);

                return;
            }

            $saved = $settings['client_secret'] ?? null;

            if (! is_string($saved) || '' === $saved) {
                $event->getForm()->get('client_secret')->addError(new FormError(
                    $this->translator->trans('einvoicing.constraint.super_pdp.client_secret_required', [], 'validators'),
                ));
            }
        });
    }

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'super_pdp_config';
    }
}
