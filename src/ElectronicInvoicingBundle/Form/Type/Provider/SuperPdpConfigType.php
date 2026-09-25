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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\NotBlank;
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

        // Never sent back to the browser: rendered into the field, the secret
        // also sat in clear in the live component's state, in the page source.
        // Left empty, the one already saved is kept — see PRE_SUBMIT below —
        // so it is only required when there is none yet.
        $builder->add('client_secret', PasswordType::class, [
            'label' => 'einvoicing.provider.super_pdp.client_secret',
            'help' => 'einvoicing.provider.super_pdp.client_secret_help',
            'always_empty' => true,
            'required' => false,
            'constraints' => new NotBlank(message: 'einvoicing.constraint.super_pdp.client_secret_required'),
        ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();
            $saved = $event->getForm()->getData();

            if (! is_array($submitted) || ! is_array($saved)) {
                return;
            }

            $secret = $saved['client_secret'] ?? null;

            if ('' === trim((string) ($submitted['client_secret'] ?? '')) && is_string($secret) && '' !== $secret) {
                $submitted['client_secret'] = $secret;
                $event->setData($submitted);
            }
        });
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'super_pdp_config';
    }
}
