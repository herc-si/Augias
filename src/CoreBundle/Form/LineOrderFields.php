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

namespace Augias\CoreBundle\Form;

use Augias\CoreBundle\Billing\LineOrder;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use function is_array;

/**
 * What every document line form carries for notes and order, and what every
 * document form does with it. See {@see LineOrder}.
 */
final class LineOrderFields
{
    /**
     * On a line form: whether the line is text only, and where it stands.
     *
     * @param FormBuilderInterface<mixed> $builder
     */
    public static function addToLine(FormBuilderInterface $builder): void
    {
        $builder->add('note', HiddenType::class, ['required' => false]);
        $builder->get('note')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $note): string => $note === true ? '1' : '0',
            static fn (mixed $note): bool => $note === '1' || $note === 1 || $note === true,
        ));

        $builder->add('position', HiddenType::class, ['required' => false]);
        $builder->get('position')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $position): string => (string) ($position ?? 0),
            static fn (mixed $position): int => is_numeric($position) ? (int) $position : 0,
        ));
    }

    /**
     * On a document form: positions settled before the lines are read, so
     * that the old lines (all at 0) and the new ones (none yet) take their
     * place in order.
     *
     * @param FormBuilderInterface<mixed> $builder
     */
    public static function addToDocument(FormBuilderInterface $builder, string $field = 'lines'): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($field): void {
            $data = $event->getData();

            if (is_array($data) && isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = LineOrder::renumber($data[$field]);
                $event->setData($data);
            }
        });
    }
}
