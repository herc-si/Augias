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
 * A provider that holds the company's VAT settings on its side (e-reporting
 * is filed on the schedule they set), and can be told Augias's.
 */
interface ElectronicInvoiceVatSettingsInterface
{
    /**
     * Sets the platform's VAT settings for the company to Augias's.
     *
     * @param array<string, mixed> $config
     *
     * @throws ElectronicInvoiceVatSettingsException when they cannot be set
     */
    public function alignVatSettings(array $config): void;
}
