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

namespace Augias\CoreBundle\DependencyInjection;

use Augias\CoreBundle\Generator\BillingIdGenerator\IdGeneratorInterface;
use Augias\CoreBundle\Generator\BillingIdGenerator\SequentialIdGeneratorInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class AugiasCoreExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->import('services/*.php');

        $container->registerForAutoconfiguration(IdGeneratorInterface::class)
            ->addTag(IdGeneratorInterface::class)
        ;

        // A second tag, so a setting that may only offer gapless numbering can
        // be handed just those strategies instead of filtering the full list.
        $container->registerForAutoconfiguration(SequentialIdGeneratorInterface::class)
            ->addTag(SequentialIdGeneratorInterface::class)
        ;
    }
}
