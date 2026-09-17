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

namespace Augias\AccountingBundle\Model;

use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use DateTimeImmutable;

/**
 * A company's accounting status, read once out of the settings and passed
 * around as a typed value instead of a scatter of string lookups.
 *
 * Everything a regime needs beyond the common fields goes in
 * {@see $regimeOptions} rather than becoming a property here — the flat-rate
 * income-tax option, the ACRE relief and the pension fund only mean anything
 * to the French micro-entreprise regime, and hard-coding them into the shared
 * profile is exactly what would make a second regime painful to add.
 */
final readonly class AccountingProfile
{
    /**
     * @param array<string, string> $regimeOptions regime-specific settings, raw
     */
    public function __construct(
        public ?string $regimeCode,
        public bool $vatExempt,
        public string $vatExemptMention,
        public ?DateTimeImmutable $activityStartDate,
        public ActivityNature $primaryActivity,
        public PeriodType $declarationPeriodicity,
        /** Null when VAT runs on the same rhythm as the books. */
        public ?PeriodType $vatPeriodicity,
        /** The month the financial year opens on, 1 to 12. */
        public int $fiscalYearStartMonth,
        public string $currencyCode,
        public array $regimeOptions = [],
    ) {
    }

    /**
     * The rhythm VAT is declared on: its own when one is set, the books' rhythm
     * otherwise.
     */
    public function vatPeriodicity(): PeriodType
    {
        return $this->vatPeriodicity ?? $this->declarationPeriodicity;
    }

    /**
     * Whether VAT is declared on a different rhythm from the books, which is
     * what makes a second set of declaration periods necessary.
     */
    public function hasOwnVatCycle(): bool
    {
        return $this->vatPeriodicity instanceof PeriodType
            && $this->vatPeriodicity !== $this->declarationPeriodicity;
    }

    /**
     * Whether a regime has been chosen at all. Until one is, the module shows
     * a setup prompt rather than empty books — an unconfigured company has no
     * business being told its turnover is within a limit it never picked.
     */
    public function isConfigured(): bool
    {
        return null !== $this->regimeCode && '' !== $this->regimeCode;
    }

    public function option(string $key, ?string $default = null): ?string
    {
        return $this->regimeOptions[$key] ?? $default;
    }

    /**
     * Settings are stored as strings, and an unchecked checkbox is `'0'` —
     * which is truthy as a non-empty string. Hence the explicit comparison
     * rather than a cast.
     */
    public function boolOption(string $key, bool $default = false): bool
    {
        $value = $this->regimeOptions[$key] ?? null;

        if (null === $value || '' === $value) {
            return $default;
        }

        return '1' === $value || 'true' === $value;
    }

    /**
     * The first day of the year the company started trading in, or null when no
     * start date is recorded. Used to decide whether a year's limits need
     * scaling down for a partial first year.
     */
    public function isFirstYearOfActivity(int $year): bool
    {
        return $this->activityStartDate instanceof DateTimeImmutable
            && (int) $this->activityStartDate->format('Y') === $year;
    }
}
