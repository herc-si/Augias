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

namespace Augias\SaasBundle\Tests\Functional;

use Augias\CoreBundle\Form\Type\CompanyType;
use Augias\MoneyBundle\Form\Type\CurrencyType;
use Augias\Test\SaasKernel;
use Override;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use function array_values;

/**
 * The hosted service sells in Europe: its currency pickers offer European
 * currencies, the euro first, and a new company starts in euros.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class EuropeanCurrenciesTest extends KernelTestCase
{
    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testThePickerOffersEuropeanCurrenciesTheEuroFirst(): void
    {
        $choices = array_values($this->forms()->create(CurrencyType::class)->getConfig()->getOption('choices'));

        self::assertSame(['EUR', 'CHF', 'GBP', 'DKK', 'SEK', 'NOK', 'ISK', 'PLN', 'CZK', 'HUF', 'RON'], $choices);
    }

    public function testASupplierBillMayStillBeInAnyCurrency(): void
    {
        self::assertContains('USD', $this->forms()->create(CurrencyType::class, options: ['restricted' => false])->getConfig()->getOption('choices'));
    }

    public function testANewCompanyStartsInEuros(): void
    {
        self::assertSame('EUR', $this->forms()->create(CompanyType::class)->get('currency')->getData());
    }

    private function forms(): FormFactoryInterface
    {
        self::bootKernel();
        $forms = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $forms);

        return $forms;
    }
}
