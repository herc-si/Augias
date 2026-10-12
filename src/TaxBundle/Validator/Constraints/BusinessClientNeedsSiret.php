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

namespace Augias\TaxBundle\Validator\Constraints;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

/**
 * A business client has a SIRET: a private individual is the box ticked on
 * the client, so a business without one is a record half filled in.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BusinessClientNeedsSiret extends Constraint
{
    public string $message = 'client.constraint.tax_identifiers.siret_required';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
