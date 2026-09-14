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

namespace Augias\AccountingBundle\Regime;

use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Model\AccountingProfile;

/**
 * A tax regime — the single thing that decides how a company's books behave:
 * which statutory books it has to keep, which activity splits are meaningful,
 * how often it declares, what limits it is measured against and what it owes.
 *
 * It extends both collaborator interfaces rather than leaving them to be wired
 * separately, so a regime is by construction complete: the registry can hand
 * back one object and callers never have to test what else it happens to
 * implement.
 *
 * Implementations are picked up automatically — see the `instanceof` tagging in
 * the bundle's `Resources/config/services/services.php`, the same mechanism the
 * electronic-invoicing providers use.
 */
interface RegimeInterface extends ThresholdProviderInterface, ContributionCalculatorInterface
{
    /**
     * Stable identifier stored in the settings and on every declaration, e.g.
     * `fr_micro`. Never translated, never reused for a different regime.
     */
    public function code(): string;

    public function labelKey(): string;

    public function descriptionKey(): string;

    /**
     * ISO 3166-1 alpha-2, used to group regimes in the settings dropdown.
     */
    public function countryCode(): string;

    /**
     * The books this regime requires. A French micro-entrepreneur always keeps
     * the revenue book, and only keeps a purchase register for resale and
     * accommodation activities — so this depends on the company's own profile,
     * not on the regime alone.
     *
     * @return list<LedgerBook>
     */
    public function books(AccountingProfile $profile): array;

    /**
     * The activity splits that mean something under this regime. Turnover is
     * recorded per nature, and a regime that does not distinguish them returns
     * a single case.
     *
     * @return list<ActivityNature>
     */
    public function activityNatures(): array;

    /**
     * How often this regime allows declaring. The user picks one of these in
     * the settings, and it is also the granularity the books are closed at.
     *
     * @return list<PeriodType>
     */
    public function declarationPeriodicities(): array;

    /**
     * Whether companies on this regime are, by default, outside the scope of
     * VAT. Only a default — it seeds the setting, and the user stays free to
     * say otherwise, since crossing a threshold makes a micro-entrepreneur
     * liable mid-year.
     */
    public function isVatExemptByDefault(): bool;

    /**
     * Where the user actually files the declaration this regime produces —
     * shown next to the figures so it is unambiguous that Augias computed them
     * but filed nothing.
     */
    public function filingUrl(): ?string;

    /**
     * Whether a document this regime governs is fixed once it has been issued.
     *
     * Where it is, an invoice that has gone to the client can no longer be
     * changed or cancelled; the correction is a credit note that points back at
     * it. France works this way, and the application has to stop offering the
     * shortcut rather than merely discourage it — otherwise the shortcut is
     * what gets used.
     *
     * A regime that answers false leaves the existing transitions alone.
     */
    public function issuedDocumentsAreFinal(): bool;
}
