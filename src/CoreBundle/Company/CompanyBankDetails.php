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

namespace Augias\CoreBundle\Company;

use Augias\CoreBundle\Entity\Company;
use Augias\SettingsBundle\SystemConfig;
use function trim;

/**
 * The bank details a company enters with its own (Settings › Company): the
 * single place invoices, their Factur-X data and the bank page read them from.
 *
 * @see \Augias\CoreBundle\Tests\Company\CompanyBankDetailsTest
 */
final readonly class CompanyBankDetails
{
    public const string BANK_NAME = 'system/company/bank_details/bank_name';

    public const string IBAN = 'system/company/bank_details/iban';

    public const string BIC = 'system/company/bank_details/bic';

    public function __construct(
        private SystemConfig $systemConfig,
    ) {
    }

    /**
     * Null without an IBAN: a bank name or a BIC alone tells a client nothing
     * they can pay to.
     */
    public function get(?Company $company = null): ?BankDetails
    {
        $iban = BankDetails::compact($this->systemConfig->get(self::IBAN, $company));

        if ('' === $iban) {
            return null;
        }

        $bic = BankDetails::compact($this->systemConfig->get(self::BIC, $company));
        $bankName = trim((string) $this->systemConfig->get(self::BANK_NAME, $company));

        return new BankDetails($iban, '' === $bic ? null : $bic, '' === $bankName ? null : $bankName);
    }
}
