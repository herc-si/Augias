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

namespace Augias\ClientBundle\Menu;

use Augias\CoreBundle\Enum\Menu\MenuPriority;
use Augias\CoreBundle\Icon;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;
use SolidWorx\Platform\PlatformBundle\Menu\Options;

final class ClientMenu
{
    #[MenuBuilder(name: 'sidebar', priority: MenuPriority::PRIORITY_CLIENT->value)]
    public function sidebar(ItemInterface $menu): void
    {
        // Clients and suppliers are one record (see Client::$isClient /
        // $isSupplier), but looked for under their own names: one entry each,
        // each list filtered to its side. A record that is both shows in both.
        $menu->addChild(
            'client.menu.main',
            Options::create()
                ->icon(Icon::CLIENT)
                ->route('_clients_index')
                ->build(),
        );

        $menu->addChild(
            'supplier.menu.main',
            Options::create()
                ->icon(Icon::SUPPLIER)
                ->route('_suppliers_index')
                ->build(),
        );
    }
}
