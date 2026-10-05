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

namespace Augias\ClientBundle\Registry;

/**
 * A French company as the official register gives it: enough to fill in a
 * client or a supplier.
 */
final readonly class RegistryCompany
{
    public function __construct(
        public string $name,
        public string $siren,
        public ?string $siret,
        public ?string $street1,
        public ?string $street2,
        public ?string $zip,
        public ?string $city,
        public bool $active,
    ) {
    }

    /**
     * The intra-community VAT number, which the register does not give but
     * every French company's SIREN decides: FR, a two-digit key, the SIREN.
     */
    public function vatNumber(): string
    {
        $key = (12 + 3 * ((int) $this->siren % 97)) % 97;

        return sprintf('FR%02d%s', $key, $this->siren);
    }
}
