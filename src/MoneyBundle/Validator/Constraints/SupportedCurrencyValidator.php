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

namespace Augias\MoneyBundle\Validator\Constraints;

use Augias\MoneyBundle\Currency\SupportedCurrencies;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use function is_string;

/**
 * Refuses a currency code that would only fail later, inside a template.
 *
 * @see \Augias\MoneyBundle\Tests\Validator\Constraints\SupportedCurrencyValidatorTest
 */
final class SupportedCurrencyValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SupportedCurrencies $supportedCurrencies,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof SupportedCurrency) {
            throw new UnexpectedTypeException($constraint, SupportedCurrency::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if ($this->supportedCurrencies->contains($value)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ value }}', $this->formatValue($value))
            ->addViolation();
    }
}
