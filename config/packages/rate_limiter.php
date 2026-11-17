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

return App::config([
    'framework' => [
        'rate_limiter' => [
            'limiters' => [
                'api_global' => [
                    'policy' => 'sliding_window',
                    'limit' => 300,
                    'interval' => '1 minute',
                ],
                // The registration page: challenges handed out, and accounts submitted, per address.
                'registration_challenge' => [
                    'policy' => 'sliding_window',
                    'limit' => 30,
                    'interval' => '1 hour',
                ],
                'registration' => [
                    'policy' => 'sliding_window',
                    'limit' => 10,
                    'interval' => '1 hour',
                ],
                'mcp_oauth_register' => [
                    'policy' => 'fixed_window',
                    'limit' => 60,
                    'interval' => '1 hour',
                ],
            ],
        ],
    ],
]);
