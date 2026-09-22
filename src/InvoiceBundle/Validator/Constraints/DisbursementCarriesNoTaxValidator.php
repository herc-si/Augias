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

namespace Augias\InvoiceBundle\Validator\Constraints;

use Augias\CoreBundle\Entity\LineInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @see \Augias\InvoiceBundle\Tests\Validator\Constraints\DisbursementCarriesNoTaxValidatorTest
 */
final class DisbursementCarriesNoTaxValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof DisbursementCarriesNoTax) {
            throw new UnexpectedTypeException($constraint, DisbursementCarriesNoTax::class);
        }

        if (null === $value) {
            return;
        }

        if (! $value instanceof LineInterface) {
            throw new UnexpectedValueException($value, LineInterface::class);
        }

        if (! $value->isDisbursement() || $value->getTaxes()->isEmpty()) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->atPath('taxes')
            ->addViolation();
    }
}
