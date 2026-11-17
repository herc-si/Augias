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

use Augias\UserBundle\Security\RegistrationChallenge;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use function is_string;

final class ProofOfWorkValidator extends ConstraintValidator
{
    public function __construct(
        private readonly RegistrationChallenge $challenge,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof ProofOfWork) {
            throw new UnexpectedTypeException($constraint, ProofOfWork::class);
        }

        if (! $this->challenge->verify(is_string($value) ? $value : null)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
