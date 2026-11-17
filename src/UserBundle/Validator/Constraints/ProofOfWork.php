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

namespace Augias\UserBundle\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * The value is the registration widget's answer to a challenge this server
 * signed (RegistrationChallenge).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ProofOfWork extends Constraint
{
    public string $message = 'user.proof_of_work.failed';
}
