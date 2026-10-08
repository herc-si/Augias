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
use Augias\CoreBundle\Billing\TermsDocument;
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
        // The default terms are seeded in the company's language: the test company's is English.
        $terms = static fn (TermsDocument $document, bool $business): string => self::getContainer()->get('translator')->trans($document->suggestionKey($business), [], null, 'en');

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
            'accounting/vat_on_debits' => '0',
            'accounting/vat_periodicity' => null,
            'credit_note/default_terms' => $terms(TermsDocument::CreditNote, true),
            'credit_note/id_generation/id_prefix' => 'AV-',
            'credit_note/id_generation/id_suffix' => '-{year}',
            'credit_note/id_generation/strategy' => 'auto_increment',
            'design/accent_color' => null,
            'design/footer_text' => null,
            'design/template' => 'default',
            'email/from_address' => 'no-reply@augias.example',
            'email/from_name' => 'Augias',
            'email/sending_options/provider' => null,
            'invoice/bcc_address' => null,
            'invoice/default_terms/business' => $terms(TermsDocument::Invoice, true),
            'invoice/default_terms/individual' => $terms(TermsDocument::Invoice, false),
            'invoice/electronic_invoicing_enabled' => '0',
            'invoice/email_subject' => null,
            'invoice/id_generation/id_prefix' => 'FACT-',
            'invoice/id_generation/id_suffix' => '-{year}',
            'invoice/id_generation/strategy' => 'auto_increment',
            'invoice/reminder/enabled' => '1',
            'invoice/reminder/pre_due_days' => '3',
            'invoice/reminder/pre_due_enabled' => '1',
            'invoice/watermark' => '1',
            'quote/bcc_address' => null,
            'quote/default_terms/business' => $terms(TermsDocument::Quote, true),
            'quote/default_terms/individual' => $terms(TermsDocument::Quote, false),
            'quote/email_subject' => null,
            'quote/id_generation/id_prefix' => '',
            'quote/id_generation/id_suffix' => '',
            'quote/id_generation/strategy' => 'auto_increment',
            'quote/watermark' => '1',
            'system/company/bank_details/bank_name' => null,
            'system/company/bank_details/bic' => null,
            'system/company/bank_details/iban' => null,
            'system/company/company_name' => 'Augias',
            'system/company/contact_details/address' => null,
            'system/company/contact_details/email' => null,
            'system/company/contact_details/phone_number' => null,
            'system/company/currency' => 'USD',
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
