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

namespace Augias\ElectronicInvoicingBundle\Onboarding;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;

/**
 * Where a company stands in the electronic invoicing setup.
 */
final readonly class OnboardingProgress
{
    public function __construct(
        /** SIREN, SIRET, VAT number unless in franchise, postcode and city. */
        public bool $identityDone,
        /** What HERC SI needs to invoice the subscriber as a company. */
        public bool $hasSiren,
        public ?ElectronicInvoiceProviderSetting $platform,
        public bool $connected,
        public ?AccountVerification $verification,
        /** SUPER PDP's "connect the account" is offered on this instance. */
        public bool $canConnectHere,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->verification === AccountVerification::Verified;
    }

    public function isDone(): bool
    {
        return $this->identityDone && $this->connected && $this->isVerified();
    }

    /**
     * The step to show open: 1 who you are, 2 the platform, 3 its verification.
     */
    public function currentStep(): int
    {
        return match (true) {
            ! $this->identityDone => 1,
            ! $this->connected => 2,
            default => 3,
        };
    }
}
