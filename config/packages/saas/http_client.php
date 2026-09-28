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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Augias\SaasBundle\Payment\Stripe\StripeApi;

return App::config([
    'framework' => [
        'http_client' => [
            'scoped_clients' => [
                'stripe' => [
                    'base_uri' => 'https://api.stripe.com/v1/',
                    'auth_bearer' => env('AUGIAS_STRIPE_SECRET_KEY'),
                    'headers' => [
                        // Pinned: the integration reads current_period_end on
                        // the subscription, which later versions moved to its items.
                        'Stripe-Version' => StripeApi::API_VERSION,
                    ],
                ],
                'lemon_squeezy' => [
                    'base_uri' => 'https://api.lemonsqueezy.com/v1/',
                    'auth_bearer' => env('AUGIAS_LEMON_SQUEEZY_API_KEY'),
                    'headers' => [
                        'Content-Type' => 'application/vnd.api+json',
                        'Accept' => 'application/vnd.api+json',
                    ],
                ],
            ],
        ],
    ],
]);
