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

namespace Augias\ElectronicInvoicingBundle\Onboarding;

/**
 * Who the company is, as the first step of the setup asks it. Mutable: it is
 * the data of that step's form.
 */
final class CompanyIdentity
{
    public function __construct(
        public ?string $siren = null,
        public ?string $siret = null,
        public ?string $vatNumber = null,
        public ?string $street1 = null,
        public ?string $street2 = null,
        public ?string $zip = null,
        public ?string $city = null,
        public bool $vatExempt = false,
        /** The name the State's register gives, when it was asked. */
        public ?string $registryName = null,
    ) {
    }
}
