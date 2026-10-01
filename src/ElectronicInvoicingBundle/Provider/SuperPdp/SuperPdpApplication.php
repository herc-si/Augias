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

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The OAuth application this deployment registered with SUPER PDP, when it
 * did: with one, a company connects its SUPER PDP account in a few clicks
 * (the "authorization code" flow — it signs up, or signs in, on SUPER PDP and
 * agrees), instead of creating an application of its own and pasting its
 * credentials.
 *
 * Unset, the default: each company brings its own credentials, as before.
 */
final readonly class SuperPdpApplication
{
    public function __construct(
        #[Autowire(env: 'AUGIAS_SUPER_PDP_CLIENT_ID')]
        public string $clientId,
        #[Autowire(env: 'AUGIAS_SUPER_PDP_CLIENT_SECRET')]
        public string $clientSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }
}
