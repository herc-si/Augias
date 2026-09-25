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

namespace Augias\AccountingBundle\Service;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\AccountingProfile;
use Augias\CoreBundle\Entity\Company;
use Augias\SettingsBundle\SystemConfig;
use DateTimeImmutable;
use Throwable;
use function trim;

/**
 * Reads the accounting settings once and hands back a typed
 * {@see AccountingProfile}, so the rest of the module never touches raw setting
 * strings or has to guess what an empty one means.
 *
 * Every value is defensive by design. Settings can be missing entirely (an
 * install that predates this bundle and has not run the seeding migration),
 * empty, or hold something no longer valid (a regime that was removed, an enum
 * case that was renamed). None of those should take the books down, so each
 * falls back to a sane default and the caller decides what to do about a
 * profile that reports itself unconfigured.
 *
 * @see \Augias\AccountingBundle\Tests\Service\AccountingProfileProviderTest
 */
final readonly class AccountingProfileProvider
{
    public function __construct(
        private SystemConfig $systemConfig,
    ) {
    }

    public function forCompany(?Company $company = null): AccountingProfile
    {
        $regimeCode = $this->trimmed(AccountingSettings::REGIME, $company);

        return new AccountingProfile(
            regimeCode: $regimeCode,
            vatExempt: $this->bool(AccountingSettings::VAT_EXEMPT, $company),
            vatExemptMention: $this->trimmed(AccountingSettings::VAT_EXEMPT_MENTION, $company)
                ?? AccountingSettings::DEFAULT_VAT_EXEMPT_MENTION,
            activityStartDate: $this->date(AccountingSettings::ACTIVITY_START_DATE, $company),
            primaryActivity: $this->activityNature($company),
            declarationPeriodicity: $this->periodicity($company),
            vatPeriodicity: $this->vatPeriodicity($company),
            fiscalYearStartMonth: $this->fiscalYearStartMonth($company),
            currencyCode: $this->currencyCode(),
            regimeOptions: $this->regimeOptions($company),
        );
    }

    /**
     * Whether invoices and quotes should suppress VAT and carry the exemption
     * wording. Read on its own — and cheaply — because the billing side asks
     * this on every document render and has no use for the rest of the profile.
     */
    public function isVatExempt(?Company $company = null): bool
    {
        return $this->bool(AccountingSettings::VAT_EXEMPT, $company);
    }

    /**
     * Whether a document issued now falls under the option for VAT on
     * debits. Never for a company outside the scope of VAT: it charges none.
     */
    public function isVatOnDebits(?Company $company = null): bool
    {
        return ! $this->isVatExempt($company) && $this->bool(AccountingSettings::VAT_ON_DEBITS, $company);
    }

    public function vatExemptMention(?Company $company = null): string
    {
        return $this->trimmed(AccountingSettings::VAT_EXEMPT_MENTION, $company)
            ?? AccountingSettings::DEFAULT_VAT_EXEMPT_MENTION;
    }

    private function trimmed(string $key, ?Company $company): ?string
    {
        $value = $this->systemConfig->get($key, $company);

        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * An unchecked checkbox is stored as the string `'0'`, which is truthy as a
     * non-empty string — so the comparison has to be explicit.
     */
    private function bool(string $key, ?Company $company): bool
    {
        $value = $this->trimmed($key, $company);

        return '1' === $value || 'true' === $value;
    }

    private function date(string $key, ?Company $company): ?DateTimeImmutable
    {
        $value = $this->trimmed($key, $company);

        if (null === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value)->setTime(0, 0);
        } catch (Throwable) {
            // A hand-edited or half-migrated value; better to behave as if no
            // start date were recorded than to break every screen that reads
            // the profile.
            return null;
        }
    }

    private function activityNature(?Company $company): ActivityNature
    {
        $value = $this->trimmed(AccountingSettings::PRIMARY_ACTIVITY, $company);

        return (null === $value ? null : ActivityNature::tryFrom($value)) ?? ActivityNature::ServicesBnc;
    }

    /**
     * Falls back to quarterly, the periodicity a French micro-entrepreneur gets
     * by default when they do not opt for monthly.
     */
    private function periodicity(?Company $company): PeriodType
    {
        $value = $this->trimmed(AccountingSettings::DECLARATION_PERIODICITY, $company);
        $periodicity = null === $value ? null : PeriodType::tryFrom($value);

        return match ($periodicity) {
            PeriodType::Month, PeriodType::Quarter => $periodicity,
            default => PeriodType::Quarter,
        };
    }

    /**
     * The month the financial year opens on.
     *
     * January unless told otherwise, and January again for anything that is not
     * a month number — a financial year cannot start on the thirteenth.
     */
    private function fiscalYearStartMonth(?Company $company): int
    {
        $value = (int) ($this->trimmed(AccountingSettings::FISCAL_YEAR_START_MONTH, $company) ?? 1);

        return $value >= 1 && $value <= 12 ? $value : 1;
    }

    /**
     * The rhythm VAT is declared on, when it is not the books' own.
     *
     * A year is allowed here and nowhere else: the régime réel simplifié wants
     * one VAT return a year, while no social regime declares turnover annually.
     * Null means the two rhythms are the same.
     */
    private function vatPeriodicity(?Company $company): ?PeriodType
    {
        $value = $this->trimmed(AccountingSettings::VAT_PERIODICITY, $company);

        if (null === $value) {
            return null;
        }

        return match (PeriodType::tryFrom($value)) {
            PeriodType::Month => PeriodType::Month,
            PeriodType::Quarter => PeriodType::Quarter,
            PeriodType::Year => PeriodType::Year,
            default => null,
        };
    }

    /**
     * The books are kept in the company's own currency. Falling back to EUR
     * rather than throwing keeps the module readable on an install whose
     * currency setting was never filled in.
     */
    private function currencyCode(): string
    {
        try {
            return $this->systemConfig->getCurrency()->getCode();
        } catch (Throwable) {
            return 'EUR';
        }
    }

    /**
     * @return array<string, string>
     */
    private function regimeOptions(?Company $company): array
    {
        $options = [];

        foreach (AccountingSettings::regimeOptionPaths() as $shortKey => $path) {
            $value = $this->trimmed($path, $company);

            if (null !== $value) {
                $options[$shortKey] = $value;
            }
        }

        return $options;
    }
}
