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

use RuntimeException;

/**
 * The platform's VAT settings could not be set; the message is a
 * translation key, or the platform's own words.
 */
final class ElectronicInvoiceVatSettingsException extends RuntimeException
{
}
