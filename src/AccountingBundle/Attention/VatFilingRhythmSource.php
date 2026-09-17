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

namespace Augias\AccountingBundle\Attention;

use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\AccountingBundle\Service\VatFilingRhythm;
use Augias\CoreBundle\Entity\Company;
use Augias\DashboardBundle\Attention\AttentionSourceInterface;
use Brick\Math\BigInteger;
use DateTimeImmutable;

/**
 * A company filing VAT quarterly that has grown past the tolerance for it.
 *
 * Quarterly filing is open while the tax payable over the trailing four
 * quarters stays under 4 000 € (CGI, art. 287). Past that, monthly returns are
 * owed — and because the window rolls, it is crossed mid-year, quietly, by a
 * company that has changed nothing about how it works.
 *
 * Only that direction is reported. "You could file quarterly" is a nicety that
 * would sit on the card every day for companies who are perfectly fine; filing
 * at a rhythm you no longer qualify for is the one worth interrupting someone
 * about, and it shows up only when it is true.
 *
 * Augias does not change the setting. The figure in the books is one input into
 * that choice and the user has others.
 *
 * @see \Augias\AccountingBundle\Tests\Attention\VatFilingRhythmSourceTest
 */
final class VatFilingRhythmSource implements AttentionSourceInterface
{
    private ?BigInteger $tax = null;

    public function __construct(
        private readonly AccountingProfileProvider $profileProvider,
        private readonly CurrentCompany $currentCompany,
        private readonly VatFilingRhythm $rhythm,
    ) {
    }

    /**
     * Cheap checks only — this runs before hasItems(), which is what queries the
     * ledger. A company on another regime, outside the scope of VAT, or already
     * filing monthly has nothing to be told here.
     */
    public function supports(): bool
    {
        $profile = $this->profileProvider->forCompany();

        return $profile->regimeCode === ReelNormalRegime::CODE
            && ! $profile->vatExempt
            && $profile->vatPeriodicity() === PeriodType::Quarter
            && $this->company() instanceof Company;
    }

    public function hasItems(): bool
    {
        return $this->taxOverTheTrailingYear()
            ->isGreaterThanOrEqualTo(BigInteger::of(VatFilingRhythm::QUARTERLY_CEILING));
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $profile = $this->profileProvider->forCompany();
        $window = $this->rhythm->trailingYear($this->today());

        return [
            'tax' => $this->taxOverTheTrailingYear(),
            'ceiling' => BigInteger::of(VatFilingRhythm::QUARTERLY_CEILING),
            'currencyCode' => $profile->currencyCode,
            'from' => $window['from'],
            'to' => $window['to'],
        ];
    }

    public function getTemplate(): string
    {
        return '@AugiasAccounting/Widget/_attention_vat_rhythm.html.twig';
    }

    /**
     * Read once per request: hasItems() and getData() both want it, and it is a
     * ledger scan over twelve months.
     */
    private function taxOverTheTrailingYear(): BigInteger
    {
        if ($this->tax instanceof BigInteger) {
            return $this->tax;
        }

        $company = $this->company();

        if (! $company instanceof Company) {
            return $this->tax = BigInteger::zero();
        }

        return $this->tax = $this->rhythm->taxOverTheTrailingYear($company, $this->today());
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    private function company(): ?Company
    {
        return $this->currentCompany->get();
    }
}
