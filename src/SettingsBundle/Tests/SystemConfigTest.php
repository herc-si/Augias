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

namespace Augias\SettingsBundle\Tests;

use const DATE_ATOM;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SettingsBundle\Entity\Setting;
use Augias\SettingsBundle\SystemConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Money\Currency;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function date;

final class SystemConfigTest extends KernelTestCase
{
    use DoctrineTestTrait;
    use MockeryPHPUnitIntegration;

    public function testGet(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertSame('Augias', $config->get('email/from_name'));
    }

    public function testGetCurrency(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertInstanceOf(Currency::class, $config->getCurrency());
        self::assertSame('USD', $config->getCurrency()->getCode());
    }

    public function testGetAll(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertSame([
            'accounting/activity_start_date' => null,
            'accounting/declaration_periodicity' => 'quarter',
            'accounting/fiscal_year_start_month' => '1',
            'accounting/fr_micro/acre' => '0',
            'accounting/fr_micro/income_tax_option' => '0',
            'accounting/fr_micro/pension_fund' => null,
            'accounting/lock_date' => null,
            'accounting/primary_activity' => 'services_bnc',
            'accounting/regime' => null,
            'accounting/vat_exempt' => '0',
            'accounting/vat_exempt_mention' => 'TVA non applicable, article 293 B du CGI',
            'accounting/vat_periodicity' => null,
            'credit_note/id_generation/id_prefix' => 'AV-',
            'credit_note/id_generation/id_suffix' => '-{year}',
            'credit_note/id_generation/strategy' => 'auto_increment',
            'email/from_address' => 'no-reply@solidinvoice.co',
            'email/from_name' => 'Augias',
            'email/sending_options/provider' => null,
            'invoice/bcc_address' => null,
            'invoice/email_subject' => 'New Invoice - #{id}',
            'invoice/id_generation/id_prefix' => 'FACT-',
            'invoice/id_generation/id_suffix' => '-{year}',
            'invoice/id_generation/strategy' => 'auto_increment',
            'invoice/reminder/enabled' => '1',
            'invoice/reminder/pre_due_days' => '3',
            'invoice/reminder/pre_due_enabled' => '1',
            'invoice/watermark' => '1',
            'quote/bcc_address' => null,
            'quote/email_subject' => 'New Quotation - #{id}',
            'quote/id_generation/id_prefix' => '',
            'quote/id_generation/id_suffix' => '',
            'quote/id_generation/strategy' => 'auto_increment',
            'quote/watermark' => '1',
            'system/company/company_name' => 'Augias',
            'system/company/contact_details/address' => null,
            'system/company/contact_details/email' => null,
            'system/company/contact_details/phone_number' => null,
            'system/company/currency' => 'USD',
            'system/company/electronic_invoicing_enabled' => '0',
            'system/company/locale' => 'en',
            'system/company/logo' => null,
        ], $config->getAll());
    }

    public function testInvalidGet(): void
    {
        $config = new SystemConfig(date(DATE_ATOM), $this->em->getRepository(Setting::class));

        self::assertNull($config->get('some/invalid/key'));
    }
}
