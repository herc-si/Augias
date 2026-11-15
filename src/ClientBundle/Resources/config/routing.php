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

use Augias\ClientBundle\Action\Add;
use Augias\ClientBundle\Action\Delete;
use Augias\ClientBundle\Action\Edit;
use Augias\ClientBundle\Action\Import;
use Augias\ClientBundle\Action\ImportTemplate;
use Augias\ClientBundle\Action\Index;
use Augias\ClientBundle\Action\View;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_clients_index', '/')
        ->controller(Index::class);

    $routingConfigurator
        ->add('_clients_add', '/add')
        ->controller(Add::class);

    $routingConfigurator
        ->add('_clients_import', '/import')
        ->controller(Import::class)
        ->methods(['GET', 'POST']);

    $routingConfigurator
        ->add('_clients_import_template', '/import/clients.csv')
        ->controller(ImportTemplate::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_clients_edit', '/edit/{id}')
        ->controller(Edit::class);

    $routingConfigurator
        ->add('_clients_view', '/view/{id}')
        ->controller(View::class);

    $routingConfigurator
        ->add('_clients_delete', '/delete/{id}')
        ->controller(Delete::class)
        ->methods(['DELETE', 'POST']);
};
