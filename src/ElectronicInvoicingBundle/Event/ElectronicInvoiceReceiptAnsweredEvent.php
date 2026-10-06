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

namespace Augias\ElectronicInvoicingBundle\Event;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once the platform has taken an answer to a received invoice —
 * never for one it refused. Lets BillBundle cancel the purchase a refusal
 * leaves owing nothing.
 */
final class ElectronicInvoiceReceiptAnsweredEvent extends Event
{
    public function __construct(
        public readonly ElectronicInvoiceReceipt $receipt,
        public readonly ReceiptResponse $response,
    ) {
    }
}
