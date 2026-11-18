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

/**
 * Where the current company stands in the electronic invoicing setup — what
 * the checkout and the return from payment ask before choosing where to go.
 */
interface OnboardingProgressProviderInterface
{
    public function progress(): OnboardingProgress;
}
