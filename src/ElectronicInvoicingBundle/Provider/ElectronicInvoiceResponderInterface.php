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

use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use RuntimeException;

/**
 * A provider through which a company can answer an invoice it received —
 * accept it, or refuse it with a reason — so the supplier learns the outcome.
 */
interface ElectronicInvoiceResponderInterface
{
    /**
     * @param array<string, mixed> $config
     *
     * @throws RuntimeException when the platform does not take the answer
     */
    public function respond(array $config, string $externalReference, ReceiptResponse $response, ?ResponseReason $reason = null, ?string $comment = null): void;
}
