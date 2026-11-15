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

namespace Augias\InvoiceBundle\Menu;

use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;

final class ReportMenu
{
    /** Between payments (60) and accounting (50): what is owed sits next to what was paid. */
    private const int PRIORITY = 55;

    #[MenuBuilder(name: 'sidebar', priority: self::PRIORITY)]
    public function sidebar(ItemInterface $menu): void
    {
        $menu->addChild(
            'report.menu',
            [
                'route' => '_reports',
                'extras' => ['icon' => 'chart-bar'],
            ],
        );
    }
}
