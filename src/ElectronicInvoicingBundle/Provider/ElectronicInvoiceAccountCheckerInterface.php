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

/**
 * A provider that can say whether the account configured for it can be used
 * at all — whose it is, and whether the platform has verified that identity.
 */
interface ElectronicInvoiceAccountCheckerInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function checkAccount(array $config): ElectronicInvoiceAccountStatus;
}
