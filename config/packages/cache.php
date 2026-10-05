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
        'cache' => [
            'pools' => [
                // The plan rights, for one request: see PlanFeatureCachePass.
                'cache.plan_features' => [
                    'adapter' => 'cache.adapter.array',
                ],
            ],
        ],
    ],
]);
