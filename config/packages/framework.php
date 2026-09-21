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
        'secret' => env('AUGIAS_APP_SECRET'),
        'php_errors' => [
            'log' => true,
        ],
        'trusted_headers' => [
            'x-forwarded-for',
            'x-forwarded-proto',
            'x-forwarded-port',
            'x-forwarded-host',
            'x-forwarded-prefix',
        ],
        'session' => [
            // Named, because a cookie is scoped to a host and ignores the port.
            // Two instances of Augias on one host — an operator console beside
            // the application, a second deployment for a trial — otherwise
            // share one cookie under one name, and signing in to either signs
            // the other out.
            //
            // Separate names also mean a session stolen from one is not a
            // session on the other, which matters most where the two instances
            // are not equally exposed.
            'name' => env('AUGIAS_SESSION_NAME'),
        ],
        'secrets' => [
            'enabled' => true,
            'vault_directory' => env('AUGIAS_CONFIG_DIR'),
        ],
    ],
]);
