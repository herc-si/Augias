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

use Augias\CoreBundle\Enum\QuantityUnit;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * What a line's quantity counts, on a document line.
 *
 * Shared by the invoice, recurring invoice, credit note and quote lines. A
 * plain count unless the user picks otherwise, as every line was before the
 * choice existed.
 *
 * @extends AbstractType<QuantityUnit>
 */
final class QuantityUnitType extends AbstractType
{
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => QuantityUnit::class,
            'label' => 'form.field.unit',
            'choice_label' => static fn (QuantityUnit $unit): string => $unit->getLabel(),
            'empty_data' => QuantityUnit::Unit->value,
            'placeholder' => false,
            'required' => true,
            'attr' => ['class' => 'form-select-sm invoice-item-unit'],
            // Eight short options: a searchable dropdown would only be in the way.
            'autocomplete' => false,
        ]);
    }

    #[Override]
    public function getParent(): string
    {
        return EnumType::class;
    }
}
