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
use Augias\ElectronicInvoicingBundle\Action\SuperPdp\Callback;
use Augias\ElectronicInvoicingBundle\Action\SuperPdp\Connect;
use Augias\ElectronicInvoicingBundle\Action\SyncNow;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnector;
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
        ->add('_einvoicing_send_credit_note', '/send-credit-note/{id}')
        ->controller([SendElectronicInvoice::class, 'creditNote']);

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

    $routingConfigurator
        ->add('_einvoicing_sync', '/sync')
        ->controller(SyncNow::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_einvoicing_super_pdp_connect', '/super-pdp/connect/{id}')
        ->controller(Connect::class)
        ->methods(['GET']);

    // Registered on SUPER PDP's side as the application's redirect URL:
    // changing this path breaks every connection until it is updated there.
    $routingConfigurator
        ->add(SuperPdpConnector::CALLBACK_ROUTE, '/super-pdp/callback')
        ->controller(Callback::class)
        ->methods(['GET']);
};
