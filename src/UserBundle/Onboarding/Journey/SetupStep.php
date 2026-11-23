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

namespace Augias\UserBundle\Onboarding\Journey;

/**
 * The steps of the setup journey, in the order they are taken (Rémi,
 * 23/11/2026): who the company is before anything is sent in its name, the
 * second factor before the company is connected to its e-invoicing platform,
 * and the first client last.
 */
enum SetupStep: string
{
    case Company = 'company';
    case Identity = 'identity';
    case Documents = 'documents';
    case Security = 'security';
    case ElectronicInvoicing = 'einvoicing';
    case FirstInvoice = 'first_invoice';
}
