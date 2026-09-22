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

namespace Augias\InvoiceBundle\Tests\Validator\Constraints;

use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Validator\Constraints\DisbursementCarriesNoTax;
use Augias\InvoiceBundle\Validator\Constraints\DisbursementCarriesNoTaxValidator;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxType;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<DisbursementCarriesNoTaxValidator>
 */
final class DisbursementCarriesNoTaxValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): DisbursementCarriesNoTaxValidator
    {
        return new DisbursementCarriesNoTaxValidator();
    }

    public function testAnOrdinaryTaxedLinePasses(): void
    {
        $line = new Line()->setPrice(10000)->setQty(1);
        $line->addTax($this->vat());

        $this->validator->validate($line, new DisbursementCarriesNoTax());

        $this->assertNoViolation();
    }

    public function testADisbursementWithoutTaxPasses(): void
    {
        $line = new Line()->setPrice(50000)->setQty(1)->setDisbursement(true);

        $this->validator->validate($line, new DisbursementCarriesNoTax());

        $this->assertNoViolation();
    }

    public function testADisbursementCarryingTaxIsRefused(): void
    {
        $line = new Line()->setPrice(50000)->setQty(1)->setDisbursement(true);
        $line->addTax($this->vat());

        $this->validator->validate($line, new DisbursementCarriesNoTax());

        $this->buildViolation('invoice.constraint.disbursement_carries_no_tax')
            ->atPath('property.path.taxes')
            ->assertRaised();
    }

    public function testNullPasses(): void
    {
        $this->validator->validate(null, new DisbursementCarriesNoTax());

        $this->assertNoViolation();
    }

    private function vat(): LineTax
    {
        $lineTax = new LineTax();
        $lineTax->setNameSnapshot('VAT 20%');
        $lineTax->setRateSnapshot('20.0000');
        $lineTax->setTypeSnapshot(TaxType::Exclusive);
        $lineTax->setCategorySnapshot(TaxCategory::Standard);

        return $lineTax;
    }
}
