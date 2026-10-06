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

namespace Augias\CoreBundle\Enum;

use horstoeko\zugferd\codelists\ZugferdUnitCodes;

/**
 * What a quantity counts: how a catalogue entry is sold, and what the quantity
 * of a document line means. Copied from the product onto the line, so a later
 * change in the catalogue never rewrites what an issued document said.
 *
 * @see \Augias\CoreBundle\Tests\Enum\QuantityUnitTest
 */
enum QuantityUnit: string
{
    case Unit = 'unit';

    case Hour = 'hour';

    case Day = 'day';

    case Month = 'month';

    case Kilogram = 'kilogram';

    case Litre = 'litre';

    case Metre = 'metre';

    case FlatRate = 'flat_rate';

    public function getLabel(): string
    {
        return 'catalog.unit.' . $this->value;
    }

    /**
     * Written with the quantity on a document, in words and agreeing with it
     * — "1 heure", "3 heures", "2 jours" — or nothing for a plain count, which
     * reads as pieces without saying so. Abbreviated ("3 h", "2 j") until
     * 06/10/2026: "affiche le mot complet".
     */
    public function quantityLabel(): ?string
    {
        return self::Unit === $this ? null : 'catalog.unit_quantity.' . $this->value;
    }

    /**
     * The UN/ECE Recommendation 20 code the electronic invoice carries for the
     * line's quantity (EN 16931, BT-130).
     */
    public function unCode(): string
    {
        return match ($this) {
            self::Unit => ZugferdUnitCodes::REC20_ONE,
            self::Hour => ZugferdUnitCodes::REC20_HOUR,
            self::Day => ZugferdUnitCodes::REC20_DAY,
            self::Month => ZugferdUnitCodes::REC20_MONTH,
            self::Kilogram => ZugferdUnitCodes::REC20_KILOGRAM,
            self::Litre => ZugferdUnitCodes::REC20_LITRE,
            self::Metre => ZugferdUnitCodes::REC20_METRE,
            self::FlatRate => ZugferdUnitCodes::REC20_LUMP_SUM,
        };
    }
}
