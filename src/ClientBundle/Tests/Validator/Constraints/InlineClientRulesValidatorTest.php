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

namespace Augias\ClientBundle\Tests\Validator\Constraints;

use Augias\ClientBundle\Validator\Constraints\InlineClientRules;
use Augias\ClientBundle\Validator\Constraints\InlineClientRulesValidator;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Enum\InvoiceClientMode;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<InlineClientRulesValidator>
 */
#[CoversClass(InlineClientRulesValidator::class)]
final class InlineClientRulesValidatorTest extends ConstraintValidatorTestCase
{
    private bool $requiresBooks = false;

    protected function createValidator(): InlineClientRulesValidator
    {
        $gate = $this->createStub(CashRegisterGateInterface::class);
        $gate->method('requiresBooks')->willReturnCallback(fn (): bool => $this->requiresBooks);

        return new InlineClientRulesValidator($gate);
    }

    public function testAnExistingClientIsNotChecked(): void
    {
        $this->validator->validate(new InvoiceFormDTO(), new InlineClientRules());

        $this->assertNoViolation();
    }

    public function testANewBusinessGivesItsSiret(): void
    {
        $constraint = new InlineClientRules();

        $this->validator->validate($this->newClient(true, null), $constraint);

        $this->buildViolation($constraint->siretMessage)
            ->atPath('property.path.newClientSiret')
            ->assertRaised();
    }

    public function testANewBusinessWithItsSiretPasses(): void
    {
        $this->validator->validate($this->newClient(true, '12345678900012'), new InlineClientRules());

        $this->assertNoViolation();
    }

    public function testANewIndividualNeedsNoSiret(): void
    {
        $this->validator->validate($this->newClient(false, null), new InlineClientRules());

        $this->assertNoViolation();
    }

    /**
     * As on the client form: a VAT-registered company keeps its books before
     * it takes a private customer's money.
     */
    public function testANewIndividualNeedsTheBooksKept(): void
    {
        $this->requiresBooks = true;
        $constraint = new InlineClientRules();

        $this->validator->validate($this->newClient(false, null), $constraint);

        $this->buildViolation($constraint->booksMessage)
            ->atPath('property.path.newClientIsCompany')
            ->assertRaised();
    }

    private function newClient(bool $company, ?string $siret): InvoiceFormDTO
    {
        $dto = new InvoiceFormDTO();
        $dto->clientMode = InvoiceClientMode::NewClient;
        $dto->newClientIsCompany = $company;
        $dto->newClientSiret = $siret;

        return $dto;
    }
}
