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

namespace Augias\MoneyBundle\Tests\Validator\Constraints;

use Augias\MoneyBundle\Currency\SupportedCurrencies;
use Augias\MoneyBundle\Validator\Constraints\SupportedCurrency;
use Augias\MoneyBundle\Validator\Constraints\SupportedCurrencyValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<SupportedCurrencyValidator>
 */
#[CoversClass(SupportedCurrency::class)]
#[CoversClass(SupportedCurrencyValidator::class)]
final class SupportedCurrencyValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): SupportedCurrencyValidator
    {
        return new SupportedCurrencyValidator(new SupportedCurrencies());
    }

    public function testNullPasses(): void
    {
        $this->validator->validate(null, new SupportedCurrency());
        $this->assertNoViolation();
    }

    public function testEmptyStringPasses(): void
    {
        // Emptiness is NotBlank's business, not this constraint's.
        $this->validator->validate('', new SupportedCurrency());
        $this->assertNoViolation();
    }

    public function testASupportedCurrencyPasses(): void
    {
        $this->validator->validate('EUR', new SupportedCurrency());
        $this->assertNoViolation();
    }

    public function testACurrencyOutOfCirculationFails(): void
    {
        $constraint = new SupportedCurrency();

        $this->validator->validate('CUC', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', '"CUC"')
            ->assertRaised();
    }

    public function testNonsenseFails(): void
    {
        $constraint = new SupportedCurrency();

        $this->validator->validate('nope', $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', '"nope"')
            ->assertRaised();
    }

    public function testANonStringIsRejectedOutright(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(978, new SupportedCurrency());
    }
}
