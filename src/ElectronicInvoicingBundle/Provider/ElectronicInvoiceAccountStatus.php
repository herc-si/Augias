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

namespace Augias\ElectronicInvoicingBundle\Provider;

use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;

/**
 * What the platform says about the account Augias uses: whose it is, which
 * environment, whether its identity is verified, and the VAT settings it
 * holds for the company — which it uses for e-reporting, and which have to
 * agree with Augias's.
 */
final readonly class ElectronicInvoiceAccountStatus
{
    /**
     * @param list<string> $warnings translation keys
     */
    public function __construct(
        public AccountVerification $verification,
        public ?string $companyName = null,
        public ?string $companyNumber = null,
        public ?string $environment = null,
        public ?string $error = null,
        public array $warnings = [],
        /**
         * Whether the platform actually answered about this account — false
         * when it could not be reached. An outage says nothing about the
         * account, and must not switch electronic invoicing off.
         */
        public bool $answered = true,
    ) {
    }

    public static function unreachable(string $error): self
    {
        return new self(AccountVerification::Unknown, error: $error, answered: false);
    }

    /**
     * The credentials themselves were refused: an answer about the account,
     * and a final one until someone changes them.
     */
    public static function refused(string $error): self
    {
        return new self(AccountVerification::Failed, error: $error);
    }

    public function isSandbox(): bool
    {
        return 'sandbox' === $this->environment;
    }

    /**
     * @return array{verification: string, companyName: ?string, companyNumber: ?string, environment: ?string, error: ?string, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'verification' => $this->verification->value,
            'companyName' => $this->companyName,
            'companyNumber' => $this->companyNumber,
            'environment' => $this->environment,
            'error' => $this->error,
            'warnings' => $this->warnings,
        ];
    }
}
