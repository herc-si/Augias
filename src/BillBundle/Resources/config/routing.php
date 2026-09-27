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

use Augias\BillBundle\Action\Add;
use Augias\BillBundle\Action\CreateFromReceipt;
use Augias\BillBundle\Action\Delete;
use Augias\BillBundle\Action\DownloadDocument;
use Augias\BillBundle\Action\Edit;
use Augias\BillBundle\Action\Import;
use Augias\BillBundle\Action\Index;
use Augias\BillBundle\Action\RecordPayment;
use Augias\BillBundle\Action\Transition;
use Augias\BillBundle\Action\View;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_bills_index', '/')
        ->controller(Index::class);

    $routingConfigurator
        ->add('_bills_add', '/add')
        ->controller(Add::class);

    $routingConfigurator
        ->add('_bills_edit', '/edit/{id}')
        ->controller(Edit::class);

    $routingConfigurator
        ->add('_bills_view', '/view/{id}')
        ->controller(View::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_bills_delete', '/delete/{id}')
        ->controller(Delete::class)
        ->methods(['DELETE', 'POST']);

    $routingConfigurator
        ->add('_bills_record_payment', '/payment/{id}')
        ->controller(RecordPayment::class)
        ->methods(['GET', 'POST']);

    $routingConfigurator
        ->add('_bills_action', '/action/{action}/{id}')
        ->controller(Transition::class);

    $routingConfigurator
        ->add('_bills_create_from_receipt', '/create-from-receipt/{id}')
        ->controller(CreateFromReceipt::class);

    // A supplier's Factur-X file, read into a draft bill.
    $routingConfigurator
        ->add('_bills_import', '/import')
        ->controller(Import::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_bills_document', '/document/{id}')
        ->controller(DownloadDocument::class)
        ->methods(['GET']);
};
