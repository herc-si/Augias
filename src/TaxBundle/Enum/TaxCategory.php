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

namespace Augias\TaxBundle\Enum;

enum TaxCategory: string
{
    case Standard = 'Standard';
    case ZeroRated = 'ZeroRated';
    case Exempt = 'Exempt';
    case OutOfScope = 'OutOfScope';
    case ReverseCharge = 'ReverseCharge';

    /**
     * The translation key of the label, for anything a user reads. getLabel()
     * stays the English name the API and the logs use.
     */
    public function labelKey(): string
    {
        return 'tax.category.' . $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::ZeroRated => 'Zero-Rated',
            self::Exempt => 'Exempt',
            self::OutOfScope => 'Out of Scope',
            self::ReverseCharge => 'Reverse Charge',
        };
    }
}
