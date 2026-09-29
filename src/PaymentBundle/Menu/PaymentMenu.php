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

namespace Augias\PaymentBundle\Menu;

use Augias\CoreBundle\Enum\Menu\MenuPriority;
use Augias\CoreBundle\Icon;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;

final readonly class PaymentMenu
{
    #[MenuBuilder(name: 'sidebar', priority: MenuPriority::PRIORITY_PAYMENT->value)]
    public function sidebar(ItemInterface $menu): void
    {
        // "Payment methods" is configuration, not a daily task, so it moved to
        // the System section (see CoreBundle\Menu\MainMenu::paymentMethods).
        // That leaves a single destination here, so no dropdown is needed.
        //
        // No "Upgrade to …" under it: the payments page is open on every plan
        // — recording a payment is not a paid feature. Only taking payments
        // online is, and its page (payment methods) carries the prompt itself.
        // Here the badge read as if the whole section were locked.
        $menu->addChild(
            'payment.menu.main',
            [
                'route' => '_payments_index',
                'extras' => ['icon' => Icon::PAYMENT],
            ],
        );
    }
}
