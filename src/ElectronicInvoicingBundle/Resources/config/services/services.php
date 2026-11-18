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

use Augias\DashboardBundle\Checklist\ChecklistItemInterface;
use Augias\ElectronicInvoicingBundle\AugiasElectronicInvoicingBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services
        ->defaults()
        ->autoconfigure()
        ->autowire()
        ->private()
        ->bind('$projectDir', param('kernel.project_dir'))
    ;

    // Tagged before load(), as the dashboard's own items are.
    $services
        ->instanceof(ChecklistItemInterface::class)
        ->tag('dashboard.checklist_item');

    $services
        ->load(AugiasElectronicInvoicingBundle::NAMESPACE . '\\', dirname(__DIR__, 3))
        ->exclude(dirname(__DIR__, 3) . '/{DependencyInjection,Entity,Resources,Tests,Test}');

    $services
        ->load(AugiasElectronicInvoicingBundle::NAMESPACE . '\\Action\\', dirname(__DIR__, 3) . '/Action')
        ->tag('controller.service_arguments');
};
