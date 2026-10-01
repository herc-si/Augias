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

use Augias\SaasBundle\Action\Support\EnterSupportAction;
use Augias\SaasBundle\Action\Support\LeaveSupportAction;
use Augias\SaasBundle\Action\Support\RevokeSupportAction;
use Augias\SaasBundle\Action\Support\SupportAction;
use Augias\SaasBundle\Action\Support\SupportEndedAction;
use Augias\UserBundle\Security\SupportPass;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Requests for help. Named with a leading underscore, unlike the billing
 * routes, so that each one has to be placed in RoutePermissionMap: asking for
 * help is letting someone in, and only those who manage the company's people
 * may do it.
 */
return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->add('_support', '/')
        ->controller(SupportAction::class)
        ->methods(['GET', 'POST']);

    $routingConfigurator->add('_support_revoke', '/{id}/revoke')
        ->controller(RevokeSupportAction::class)
        ->methods(['POST']);

    // The visitor's side: arriving, leaving and having left need no company
    // of their own, and must stay reachable while inside someone else's.
    $routingConfigurator->add('_support_enter', '/{id}/enter')
        ->controller(EnterSupportAction::class)
        ->methods(['GET'])
        ->defaults(['_company_scope' => false, SupportPass::OPEN_ROUTE_ATTRIBUTE => true]);

    $routingConfigurator->add('_support_leave', '/leave')
        ->controller(LeaveSupportAction::class)
        ->methods(['POST'])
        ->defaults(['_company_scope' => false, SupportPass::OPEN_ROUTE_ATTRIBUTE => true]);

    $routingConfigurator->add('_support_ended', '/ended')
        ->controller(SupportEndedAction::class)
        ->methods(['GET'])
        ->defaults(['_company_scope' => false, SupportPass::OPEN_ROUTE_ATTRIBUTE => true]);
};
