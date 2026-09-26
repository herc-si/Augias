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

namespace Augias\ClientBundle\Validator\Constraints;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @see \Augias\ClientBundle\Tests\Validator\Constraints\PrivateCustomerNeedsBooksValidatorTest
 */
final class PrivateCustomerNeedsBooksValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CashRegisterGateInterface $gate,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof PrivateCustomerNeedsBooks) {
            throw new UnexpectedTypeException($constraint, PrivateCustomerNeedsBooks::class);
        }

        if (! $value instanceof Client) {
            throw new UnexpectedValueException($value, Client::class);
        }

        // A business, or a supplier only: nobody whose payments are taken.
        if ($value->isCompany() || ! $value->isClient()) {
            return;
        }

        // A new record has no company yet: the one being worked in is meant.
        $company = null === $value->getCompanyId() ? null : $value->getCompany();

        if (! $this->gate->requiresBooks($company)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->atPath('name')
            ->addViolation();
    }
}
