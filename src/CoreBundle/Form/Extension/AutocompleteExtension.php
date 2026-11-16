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

namespace Augias\CoreBundle\Form\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

#[AutoconfigureTag('form.type_extension', attributes: ['priority' => -1])]
class AutocompleteExtension extends AbstractTypeExtension
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        // Radios and checkboxes render as a <div>: the autocomplete controller only
        // accepts an <input> or a <select> and throws in the browser otherwise.
        $resolver->setDefault('autocomplete', static fn (Options $options): bool => $options['expanded'] !== true);
    }

    public static function getExtendedTypes(): iterable
    {
        yield ChoiceType::class;
    }
}
