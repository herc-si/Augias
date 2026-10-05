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

namespace Augias\SaasBundle\DependencyInjection\Compiler;

use SolidWorx\Platform\SaasBundle\Feature\PlanFeatureManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives the plan rights their own cache, held in memory for one request.
 *
 * On cache.app they were kept in files, per container: the app, the worker,
 * the cron and the operator console each had their own, never told when the
 * console changed a right, so the app went on with the old one (05/10/2026).
 * Read from the database once per request instead: a handful of small
 * queries, and every process sees the plans as they are. The manager's
 * reset() also emptied the whole of cache.app; now it empties this alone.
 *
 * @see \Augias\SaasBundle\Tests\Functional\PlanFeatureCacheTest
 */
final class PlanFeatureCachePass implements CompilerPassInterface
{
    public const string POOL = 'cache.plan_features';

    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition(PlanFeatureManager::class) || ! $container->has(self::POOL)) {
            return;
        }

        $container->getDefinition(PlanFeatureManager::class)->setArgument('$cache', new Reference(self::POOL));
    }
}
