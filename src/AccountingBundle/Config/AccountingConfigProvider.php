<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Config;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Form\Type\ActivityNatureChoiceType;
use Augias\AccountingBundle\Form\Type\DeclarationPeriodicityChoiceType;
use Augias\AccountingBundle\Form\Type\FiscalYearStartMonthChoiceType;
use Augias\AccountingBundle\Form\Type\PensionFundChoiceType;
use Augias\AccountingBundle\Form\Type\RegimeChoiceType;
use Augias\AccountingBundle\Form\Type\VatPeriodicityChoiceType;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Seeds the accounting settings for a newly created company.
 *
 * This only covers new companies — {@see \Augias\CoreBundle\Company\DefaultData}
 * walks every provider when a company is created. Companies that already exist
 * are served by the migration that inserts the same rows, because
 * {@see \Augias\SettingsBundle\Repository\SettingsRepository::store()} updates
 * but never inserts. Adding a setting here without the matching migration
 * leaves it invisible to every existing install.
 *
 * Declaration order matters: settings are read back ordered by their ULID, so
 * the order below is the order the fields appear on the tab.
 *
 * @see \Augias\AccountingBundle\Tests\Config\AccountingConfigProviderTest
 */
final class AccountingConfigProvider implements ProviderInterface
{
    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config(
                AccountingSettings::REGIME,
                null,
                'accounting.settings.regime.description',
                RegimeChoiceType::class,
                ['label' => 'accounting.settings.regime.label'],
            ),
            new Config(
                AccountingSettings::PRIMARY_ACTIVITY,
                ActivityNature::ServicesBnc->value,
                'accounting.settings.primary_activity.description',
                ActivityNatureChoiceType::class,
                ['label' => 'accounting.settings.primary_activity.label'],
            ),
            new Config(
                AccountingSettings::ACTIVITY_START_DATE,
                null,
                'accounting.settings.activity_start_date.description',
                DateType::class,
                [
                    'label' => 'accounting.settings.activity_start_date.label',
                    // Settings round-trip as plain strings, so the field has to
                    // read and write one rather than a DateTime object.
                    'input' => 'string',
                    'widget' => 'single_text',
                ],
            ),
            new Config(
                AccountingSettings::DECLARATION_PERIODICITY,
                PeriodType::Quarter->value,
                'accounting.settings.declaration_periodicity.description',
                DeclarationPeriodicityChoiceType::class,
                ['label' => 'accounting.settings.declaration_periodicity.label'],
            ),
            new Config(
                AccountingSettings::FISCAL_YEAR_START_MONTH,
                '1',
                'accounting.settings.fiscal_year_start_month.description',
                FiscalYearStartMonthChoiceType::class,
                ['label' => 'accounting.settings.fiscal_year_start_month.label'],
            ),
            new Config(
                AccountingSettings::VAT_PERIODICITY,
                null,
                'accounting.settings.vat_periodicity.description',
                VatPeriodicityChoiceType::class,
                ['label' => 'accounting.settings.vat_periodicity.label'],
            ),
            new Config(
                AccountingSettings::LOCK_DATE,
                null,
                'accounting.settings.lock_date.description',
                DateType::class,
                [
                    'label' => 'accounting.settings.lock_date.label',
                    // Settings round-trip as plain strings, so the field has to
                    // read and write one rather than a DateTime object.
                    'input' => 'string',
                    'widget' => 'single_text',
                ],
            ),
            new Config(
                AccountingSettings::VAT_EXEMPT,
                '0',
                'accounting.settings.vat_exempt.description',
                CheckboxType::class,
                ['label' => 'accounting.settings.vat_exempt.label'],
            ),
            new Config(
                AccountingSettings::VAT_EXEMPT_MENTION,
                AccountingSettings::DEFAULT_VAT_EXEMPT_MENTION,
                'accounting.settings.vat_exempt_mention.description',
                TextType::class,
                ['label' => 'accounting.settings.vat_exempt_mention.label'],
            ),
            new Config(
                AccountingSettings::VAT_ON_DEBITS,
                '0',
                'accounting.settings.vat_on_debits.description',
                CheckboxType::class,
                ['label' => 'accounting.settings.vat_on_debits.label'],
            ),
            new Config(
                AccountingSettings::FR_INCOME_TAX_OPTION,
                '0',
                'accounting.settings.income_tax_option.description',
                CheckboxType::class,
                ['label' => 'accounting.settings.income_tax_option.label'],
            ),
            new Config(
                AccountingSettings::FR_ACRE,
                '0',
                'accounting.settings.acre.description',
                CheckboxType::class,
                ['label' => 'accounting.settings.acre.label'],
            ),
            new Config(
                AccountingSettings::FR_PENSION_FUND,
                null,
                'accounting.settings.pension_fund.description',
                PensionFundChoiceType::class,
                ['label' => 'accounting.settings.pension_fund.label'],
            ),
        ];
    }
}
