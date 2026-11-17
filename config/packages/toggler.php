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
    'toggler' => [
        'config' => [
            'features' => [
                'allow_registration' => env('AUGIAS_ALLOW_REGISTRATION'),
                'google_oauth_login' => '@=env("AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_ID") !== null && env("AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_SECRET") !== null',
                'saas_enabled' => '@=env("AUGIAS_PLATFORM") === \'saas\'',
                'meilisearch_search' => '@=env("AUGIAS_MEILISEARCH_URL") !== "" && env("AUGIAS_MEILISEARCH_API_KEY") !== ""',
            ],
        ],
    ],
]);
