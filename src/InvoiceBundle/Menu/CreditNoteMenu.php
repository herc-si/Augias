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

use Augias\CoreBundle\Enum\Menu\MenuPriority;
use Augias\CoreBundle\Icon;
use Augias\CoreBundle\Menu\PrestationMenu;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;

final class CreditNoteMenu
{
    #[MenuBuilder(name: 'sidebar', priority: MenuPriority::PRIORITY_CREDIT_NOTE->value)]
    public function sidebar(ItemInterface $menu): void
    {
        // Its own entry rather than a child of invoices: a credit note is a
        // document in its own right, with its own numbering, and looking one up
        // is a thing people do on purpose.
        PrestationMenu::section($menu)->addChild(
            'credit_note.menu.main',
            [
                'route' => '_credit_notes_index',
                'extras' => [
                    'icon' => Icon::CREDIT_NOTE,
                ],
            ],
        );
    }
}
