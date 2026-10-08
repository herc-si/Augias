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

use function implode;
use function preg_replace;
use function str_split;
use function strtoupper;
use function trim;

/**
 * The account a company's clients pay into by transfer: what its invoices
 * print under "Payment" and what their Factur-X data carries (BG-16).
 *
 * @see CompanyBankDetails
 */
final readonly class BankDetails
{
    /**
     * @param string $iban without spaces, upper-cased
     */
    public function __construct(
        public string $iban,
        public ?string $bic = null,
        public ?string $bankName = null,
    ) {
    }

    /**
     * An IBAN or a BIC as it is stored: no spaces, upper-cased.
     */
    public static function compact(?string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim((string) $value)));
    }

    /**
     * Grouped by four, as it is printed on a RIB.
     */
    public function groupedIban(): string
    {
        return implode(' ', str_split($this->iban, 4));
    }
}
