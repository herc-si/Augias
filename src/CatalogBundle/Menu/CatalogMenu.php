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

namespace Augias\CatalogBundle\Menu;

use Augias\CoreBundle\Enum\Menu\MenuPriority;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;

final class CatalogMenu
{
    #[MenuBuilder(name: 'sidebar', priority: MenuPriority::PRIORITY_CATALOG->value)]
    public function sidebar(ItemInterface $menu): void
    {
        $menu->addChild(
            'catalog.menu.main',
            [
                'route' => '_catalog_index',
                'extras' => [
                    'icon' => 'package',
                ],
            ],
        );

        // Right under the catalogue, where they are mostly looked for, rather
        // than among the system settings. Still the one list purchases share -
        // see CoreBundle\Entity\Category.
        $menu->addChild(
            'menu.top.categories',
            [
                'route' => '_categories_index',
                'extras' => ['icon' => 'category'],
            ],
        );
    }
}
