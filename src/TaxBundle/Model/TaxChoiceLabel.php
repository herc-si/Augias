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

namespace Augias\TaxBundle\Model;

use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * How a tax rate reads in a dropdown: its name, its rate, and the category when
 * it is not a standard one. Built as a translatable message so the category
 * reads in the user's language, where a string glued together in English
 * ("[exempt]") reached the French interface untouched.
 */
final class TaxChoiceLabel
{
    public static function for(Tax $tax, bool $showCompound = false): TranslatableMessage
    {
        $rate = $tax->getRate() ?? 0;
        $rateLabel = $tax->getType() === Tax::TYPE_FLAT_RATE ? (string) $rate : $rate . '%';

        $label = new TranslatableMessage(
            $showCompound && $tax->isCompound() ? 'tax.choice.compound' : 'tax.choice.label',
            ['%name%' => $tax->getName() ?? '', '%rate%' => $rateLabel],
        );

        $category = $tax->getCategory();

        if (TaxCategory::Standard === $category) {
            return $label;
        }

        return new TranslatableMessage('tax.choice.with_category', [
            '%label%' => $label,
            '%category%' => new TranslatableMessage($category->labelKey()),
        ]);
    }
}
