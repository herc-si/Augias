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

use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Contracts\PaidSubscriptionGateInterface;
use Augias\CoreBundle\Feature\UpgradePromptProvider;
use Augias\DashboardBundle\Checklist\ChecklistItemInterface;
use Augias\SaasBundle\AugiasSaasBundle;
use Augias\SaasBundle\Email\SaasEmailVerificationGate;
use Augias\SaasBundle\Feature\UpgradePromptRenderer;
use Augias\SaasBundle\Payment\PaymentIntegrationSelector;
use Augias\SaasBundle\Service\SubscriptionEligibility;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private()
    ;

    // Tag checklist items BEFORE load()
    $services
        ->instanceof(ChecklistItemInterface::class)
        ->tag('dashboard.checklist_item');

    $services
        ->load(AugiasSaasBundle::NAMESPACE . '\\', dirname(__DIR__, 3))
        ->exclude(dirname(__DIR__, 3) . '/{DependencyInjection,Entity,Message,Resources,Tests}');

    $services
        ->load(AugiasSaasBundle::NAMESPACE . '\\Action\\', dirname(__DIR__, 3) . '/Action')
        ->tag('controller.service_arguments');

    $services
        ->load(AugiasSaasBundle::NAMESPACE . '\\Controller\\', dirname(__DIR__, 3) . '/Controller')
        ->tag('controller.service_arguments');

    $services->alias(
        EmailVerificationGateInterface::class,
        SaasEmailVerificationGate::class,
    );

    $services->alias(
        UpgradePromptProvider::class,
        UpgradePromptRenderer::class,
    );

    // Two providers implement it; the selector picks one at runtime.
    $services->alias(
        PaymentIntegrationInterface::class,
        PaymentIntegrationSelector::class,
    );

    $services->alias(
        PaidSubscriptionGateInterface::class,
        SubscriptionEligibility::class,
    );
};
