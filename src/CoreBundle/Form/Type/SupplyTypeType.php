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

use Augias\CoreBundle\Enum\SupplyType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Goods or a service, on a document line.
 *
 * Shared by the invoice, recurring invoice, credit note and quote lines, which
 * all have to say the same thing the same way. Services unless the user picks
 * otherwise, as every line was before the choice existed.
 *
 * @extends AbstractType<SupplyType>
 */
final class SupplyTypeType extends AbstractType
{
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => SupplyType::class,
            'label' => 'form.field.supply_type',
            'choice_label' => static fn (SupplyType $type): string => $type->translationKey(),
            'empty_data' => SupplyType::Services->value,
            'placeholder' => false,
            'required' => true,
            'attr' => ['class' => 'form-select-sm'],
            // Two options: a searchable dropdown would only be in the way.
            'autocomplete' => false,
        ]);
    }

    #[Override]
    public function getParent(): string
    {
        return EnumType::class;
    }
}
