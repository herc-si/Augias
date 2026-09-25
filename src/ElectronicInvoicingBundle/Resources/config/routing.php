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

use Augias\ElectronicInvoicingBundle\Action\DownloadIncomingInvoice;
use Augias\ElectronicInvoicingBundle\Action\IncomingInvoices;
use Augias\ElectronicInvoicingBundle\Action\Providers;
use Augias\ElectronicInvoicingBundle\Action\RespondToIncomingInvoice;
use Augias\ElectronicInvoicingBundle\Action\SendElectronicInvoice;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_einvoicing_providers', '/providers')
        ->controller(Providers::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_einvoicing_send', '/send/{id}')
        ->controller(SendElectronicInvoice::class);

    $routingConfigurator
        ->add('_einvoicing_incoming', '/incoming')
        ->controller(IncomingInvoices::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_einvoicing_incoming_download', '/incoming/download/{id}')
        ->controller(DownloadIncomingInvoice::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_einvoicing_incoming_respond', '/incoming/respond/{id}')
        ->controller(RespondToIncomingInvoice::class)
        ->methods(['GET', 'POST']);
};
