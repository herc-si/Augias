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

namespace Augias\UserBundle\Tests\Security;

use Augias\UserBundle\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use function array_filter;
use function array_keys;
use function str_starts_with;

/**
 * Every screen of the application says which right it needs. A route added
 * and forgotten in the map would open to every member of every company.
 */
#[CoversClass(RoutePermissionMap::class)]
final class RoutePermissionMapTest extends KernelTestCase
{
    private const array FRAMEWORK_PREFIXES = ['_profiler', '_wdt', '_preview_error', '_api_'];

    public function testEveryApplicationRouteIsMapped(): void
    {
        $map = new RoutePermissionMap();
        $routes = array_keys(self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all());

        $unmapped = array_filter($routes, static function (string $route) use ($map): bool {
            if (! str_starts_with($route, '_')) {
                return false;
            }

            foreach (self::FRAMEWORK_PREFIXES as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    return false;
                }
            }

            return ! $map->isMapped($route);
        });

        self::assertSame([], array_values($unmapped), 'Add these routes to RoutePermissionMap::ROUTES.');
    }
}
