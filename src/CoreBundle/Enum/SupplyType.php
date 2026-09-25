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

/**
 * What a line sells: goods, or a service.
 *
 * Not a detail of presentation. French VAT falls due at a different moment for
 * each (CGI art. 269, 2): on a supply of goods when the goods are delivered —
 * or when a deposit is received, if that comes first — and on a service when
 * the price is received. A company that declares both on payment declares its
 * goods late.
 *
 * On the line rather than the document, because one invoice can carry both,
 * and each part follows its own rule.
 */
enum SupplyType: string
{
    case Goods = 'goods';

    case Services = 'services';

    public function translationKey(): string
    {
        return 'billing.supply_type.' . $this->value;
    }

    /**
     * Whether the VAT on this line falls due when the document is issued
     * rather than when it is paid.
     *
     * The invoice date stands in for the delivery date, which Augias does not
     * record: an invoice for goods has to be issued on delivery (CGI art. 289,
     * I-3), so the two are the same day in the ordinary case.
     */
    public function isTaxedOnIssue(): bool
    {
        return $this === self::Goods;
    }
}
