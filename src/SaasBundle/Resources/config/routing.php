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

use Augias\SaasBundle\Action\CancelDowngradeAction;
use Augias\SaasBundle\Action\ChangePlanAction;
use Augias\SaasBundle\Action\ChoosePlanAction;
use Augias\SaasBundle\Action\ConfirmPlanChangeAction;
use Augias\SaasBundle\Action\SelectPlanAction;
use Augias\SaasBundle\Action\SubscriptionOverviewAction;
use Augias\SaasBundle\Controller\PaymentSuccess;
use Augias\SaasBundle\Controller\SubscribeController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->add('billing_index', '/')
        ->controller(SubscriptionOverviewAction::class)
        ->methods(['GET']);

    $routingConfigurator->add('saas_payment_success', '/payment/success')
        ->controller(PaymentSuccess::class);

    $routingConfigurator->add('saas_subscription_checkout', '/subscription/activate')
        ->controller(SubscribeController::class);

    $routingConfigurator->add('saas_subscription_plans', '/subscription/plans')
        ->controller(SelectPlanAction::class)
        ->methods(['GET']);

    $routingConfigurator->add('saas_subscription_choose', '/subscription/plans/choose')
        ->controller(ChoosePlanAction::class)
        ->methods(['POST']);

    $routingConfigurator->add('saas_subscription_change', '/subscription/change')
        ->controller(ChangePlanAction::class)
        ->methods(['GET']);

    $routingConfigurator->add('saas_subscription_change_confirm', '/subscription/change/confirm')
        ->controller(ConfirmPlanChangeAction::class)
        ->methods(['POST']);

    $routingConfigurator->add('saas_subscription_cancel_downgrade', '/subscription/cancel-downgrade')
        ->controller(CancelDowngradeAction::class)
        ->methods(['POST']);
};
