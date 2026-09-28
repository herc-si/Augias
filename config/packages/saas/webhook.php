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

use Augias\SaasBundle\Payment\Stripe\StripeRequestParser;

// POST /webhook/stripe: the endpoint to declare in the Stripe dashboard, with
// the customer.subscription.* events. Its signing secret goes in
// AUGIAS_STRIPE_WEBHOOK_SECRET.
return App::config([
    'framework' => [
        'webhook' => [
            'routing' => [
                'stripe' => [
                    'service' => StripeRequestParser::class,
                    'secret' => env('AUGIAS_STRIPE_WEBHOOK_SECRET'),
                ],
            ],
        ],
    ],
]);
