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

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->import('@AugiasSaasBundle/Resources/config/routing.php')
        ->prefix('/billing');

    $routingConfigurator->import('@AugiasSaasBundle/Resources/config/support_routing.php')
        ->prefix('/support');
};
