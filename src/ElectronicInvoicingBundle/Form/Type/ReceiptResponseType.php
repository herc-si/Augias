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

use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\RefusalReason;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Accept a received invoice, or refuse it — and a refusal says why: the
 * supplier has to know what to correct.
 *
 * @extends AbstractType<array{response: ReceiptResponse|null, reason: RefusalReason|null, comment: string|null}>
 */
final class ReceiptResponseType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('response', EnumType::class, [
            'class' => ReceiptResponse::class,
            'label' => 'einvoicing.response.form.response',
            'choice_label' => static fn (ReceiptResponse $response): string => $response->translationKey(),
            'expanded' => true,
            'autocomplete' => false,
        ]);

        $builder->add('reason', EnumType::class, [
            'class' => RefusalReason::class,
            'label' => 'einvoicing.response.form.reason',
            'help' => 'einvoicing.response.form.reason_help',
            'choice_label' => static fn (RefusalReason $reason): string => $reason->translationKey(),
            'placeholder' => 'einvoicing.response.form.reason_placeholder',
            'required' => false,
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'einvoicing.response.form.comment',
            'required' => false,
        ]);

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();

            if (($data['response'] ?? null) instanceof ReceiptResponse
                && $data['response']->needsReason()
                && ! ($data['reason'] ?? null) instanceof RefusalReason) {
                $event->getForm()->get('reason')->addError(new FormError(
                    $this->translator->trans('einvoicing.response.reason_required'),
                ));
            }
        });
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'einvoicing_receipt_response';
    }
}
