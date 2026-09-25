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

namespace Augias\CatalogBundle\Enum;

use Augias\CoreBundle\Enum\SupplyType;

enum ProductType: string
{
    case Product = 'product';

    case Service = 'service';

    /**
     * What a line built from this product sells, which decides when its VAT
     * falls due.
     */
    public function supplyType(): SupplyType
    {
        return match ($this) {
            self::Product => SupplyType::Goods,
            self::Service => SupplyType::Services,
        };
    }

    public function getLabel(): string
    {
        return 'catalog.type.' . $this->value;
    }
}
