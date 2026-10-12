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

namespace Augias\ClientBundle\Form;

/**
 * A document form that can create its client on the spot, as the invoice and
 * quote forms do.
 */
interface InlineClient
{
    public function isNewClient(): bool;

    public function isNewClientCompany(): bool;

    public function getNewClientSiret(): ?string;
}
