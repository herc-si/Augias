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

namespace Augias\UserBundle\Menu;

use Augias\UserBundle\Security\RouteAccess;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;
use function is_array;
use function is_string;

/**
 * Takes out of the menus what the member's role does not open.
 *
 * Runs after every other builder, and reads the same map the requests are held
 * to — so a link is never offered that would end on a refusal, and nothing has
 * to be said twice. A section left empty goes too.
 */
final readonly class RoleMenuFilter
{
    private const int LAST = -1000;

    public function __construct(
        private RouteAccess $routeAccess,
    ) {
    }

    #[MenuBuilder(name: 'sidebar', priority: self::LAST)]
    public function sidebar(ItemInterface $menu): void
    {
        $this->filter($menu);
    }

    private function filter(ItemInterface $item): void
    {
        foreach ($item->getChildren() as $child) {
            $route = $this->route($child);

            if (null !== $route && ! $this->routeAccess->allows($route)) {
                $item->removeChild($child);

                continue;
            }

            $hadChildren = $child->hasChildren();
            $this->filter($child);

            if ($hadChildren && ! $child->hasChildren() && null === $child->getUri()) {
                $item->removeChild($child);
            }
        }
    }

    private function route(ItemInterface $item): ?string
    {
        $routes = $item->getExtra('routes');

        return is_array($routes) && isset($routes[0]['route']) && is_string($routes[0]['route']) ? $routes[0]['route'] : null;
    }
}
