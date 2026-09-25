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
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_filter;
use function array_values;

/**
 * Accept a received invoice, dispute it, or refuse it — and a dispute or a
 * refusal says why: the supplier has to know what to correct. Once disputed,
 * it can only be accepted or refused.
 *
 * @extends AbstractType<array{response: ReceiptResponse|null, reason: ResponseReason|null, comment: string|null}>
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
            'choices' => array_values(array_filter(
                ReceiptResponse::cases(),
                static fn (ReceiptResponse $response): bool => $response !== $options['previous'],
            )),
            'label' => 'einvoicing.response.form.response',
            'choice_label' => static fn (ReceiptResponse $response): string => $response->translationKey(),
            'expanded' => true,
            'autocomplete' => false,
        ]);

        $builder->add('reason', EnumType::class, [
            'class' => ResponseReason::class,
            'label' => 'einvoicing.response.form.reason',
            'help' => 'einvoicing.response.form.reason_help',
            'choice_label' => static fn (ResponseReason $reason): string => $reason->translationKey(),
            'group_by' => static fn (ResponseReason $reason): string => $reason->isRefusal() ? 'einvoicing.response.form.reason_group.any' : 'einvoicing.response.form.reason_group.dispute',
            'placeholder' => 'einvoicing.response.form.reason_placeholder',
            'required' => false,
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'einvoicing.response.form.comment',
            'required' => false,
        ]);

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();

            $response = $data['response'] ?? null;
            $reason = $data['reason'] ?? null;

            if (! $response instanceof ReceiptResponse || ! $response->needsReason()) {
                return;
            }

            if (! $reason instanceof ResponseReason) {
                $event->getForm()->get('reason')->addError(new FormError(
                    $this->translator->trans('einvoicing.response.reason_required'),
                ));
            } elseif (! $reason->isAllowedFor($response)) {
                $event->getForm()->get('reason')->addError(new FormError(
                    $this->translator->trans('einvoicing.response.reason_not_allowed'),
                ));
            }
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        // The answer already sent — a dispute — which is not offered again.
        $resolver->setDefault('previous', null);
        $resolver->setAllowedTypes('previous', ['null', ReceiptResponse::class]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'einvoicing_receipt_response';
    }
}
