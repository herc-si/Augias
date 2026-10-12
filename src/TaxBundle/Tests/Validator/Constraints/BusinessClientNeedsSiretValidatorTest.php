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

namespace Augias\TaxBundle\Tests\Validator\Constraints;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\TaxBundle\Validator\Constraints\BusinessClientNeedsSiret;
use Augias\TaxBundle\Validator\Constraints\BusinessClientNeedsSiretValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<BusinessClientNeedsSiretValidator>
 */
#[CoversClass(BusinessClientNeedsSiret::class)]
#[CoversClass(BusinessClientNeedsSiretValidator::class)]
final class BusinessClientNeedsSiretValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): BusinessClientNeedsSiretValidator
    {
        return new BusinessClientNeedsSiretValidator();
    }

    public function testSkipsNullValue(): void
    {
        $this->validator->validate(null, new BusinessClientNeedsSiret());

        $this->assertNoViolation();
    }

    public function testSkipsAPureSupplier(): void
    {
        $client = new Client();
        $client->setIsClient(false)->setIsSupplier(true);

        $this->validator->validate($client, new BusinessClientNeedsSiret());

        $this->assertNoViolation();
    }

    public function testSkipsAnIndividual(): void
    {
        $client = new Client();
        $client->setIsCompany(false);

        $this->validator->validate($client, new BusinessClientNeedsSiret());

        $this->assertNoViolation();
    }

    public function testNoViolationWithASiret(): void
    {
        $client = new Client();
        $client->setSiret('12345678900012');

        $this->validator->validate($client, new BusinessClientNeedsSiret());

        $this->assertNoViolation();
    }

    /**
     * Whether electronic invoicing is on or not: a business without a SIRET is
     * neither e-reported nor invoiced electronically.
     */
    public function testViolationForABusinessWithoutASiret(): void
    {
        $constraint = new BusinessClientNeedsSiret();
        $client = new Client();
        $client->setSiren('123456789');

        $this->validator->validate($client, $constraint);

        $this->buildViolation($constraint->message)
            ->atPath('property.path.taxIdentifiers')
            ->assertRaised();
    }

    public function testABusinessInFranceNeedsOneEvenWithAnAddressAbroad(): void
    {
        $constraint = new BusinessClientNeedsSiret();
        $client = new Client();
        $client->addAddress(new Address()->setCountry('BE'));
        $client->addAddress(new Address()->setCountry('FR'));

        $this->validator->validate($client, $constraint);

        $this->buildViolation($constraint->message)
            ->atPath('property.path.taxIdentifiers')
            ->assertRaised();
    }

    /**
     * A business abroad has no SIRET to give.
     */
    public function testSkipsABusinessAbroad(): void
    {
        $client = new Client();
        $client->addAddress(new Address()->setCountry('BE'));

        $this->validator->validate($client, new BusinessClientNeedsSiret());

        $this->assertNoViolation();
    }

    public function testWrongConstraintTypeThrows(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(new Client(), $this->createStub(Constraint::class));
    }

    public function testWrongValueTypeThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate('not-a-client', new BusinessClientNeedsSiret());
    }
}
