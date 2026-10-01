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

namespace Augias\SaasBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * What the company needs help with, and how long it opens its door for.
 *
 * @extends AbstractType<array{message: string, hours: int}>
 */
final class SupportRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('message', TextareaType::class, [
                'label' => 'support.form.message',
                'help' => 'support.form.message_help',
                'attr' => ['rows' => 5],
                'constraints' => [new NotBlank(), new Length(max: 5000)],
            ])
            ->add('hours', ChoiceType::class, [
                'label' => 'support.form.hours',
                'help' => 'support.form.hours_help',
                'choices' => $options['durations'],
                'choice_label' => static fn (int $hours): string => 'support.duration.' . ($hours % 24 === 0 ? 'days' : 'hours'),
                'choice_translation_parameters' => static fn (int $hours): array => ['%count%' => $hours % 24 === 0 ? intdiv($hours, 24) : $hours],
                'data' => $options['default_hours'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('durations');
        $resolver->setAllowedTypes('durations', 'int[]');
        $resolver->setDefault('default_hours', null);
        $resolver->setAllowedTypes('default_hours', ['int', 'null']);
    }
}
