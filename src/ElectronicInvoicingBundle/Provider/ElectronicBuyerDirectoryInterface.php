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

namespace Augias\ElectronicInvoicingBundle\Provider;

use Augias\ClientBundle\Entity\Client;

/**
 * A provider that can tell whether a buyer is in the e-invoicing directory:
 * an invoice to one that is not is still made available on the platform, but
 * no status about it — « encaissée » first — can be delivered to it.
 */
interface ElectronicBuyerDirectoryInterface
{
    /**
     * True when statuses can reach $buyer, false when the directory lists no
     * address for it, null when that cannot be known.
     *
     * @param array<string, mixed> $config
     */
    public function buyerReachable(array $config, Client $buyer): ?bool;
}
