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

namespace Augias\SaasBundle\Menu;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Enum\Menu\MenuPriority;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Support\SupportDesk;
use Augias\UserBundle\Enum\CompanyPermission;
use Augias\UserBundle\Security\CompanyAccess;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;

final readonly class SaasMenu
{
    public function __construct(
        private CompanySelector $companySelector,
        private CompanyRepository $companyRepository,
        private SubscriptionManager $subscriptionManager,
        private SupportDesk $supportDesk,
        private CompanyAccess $access,
    ) {
    }

    #[MenuBuilder(name: 'sidebar', priority: MenuPriority::PRIORITY_SYSTEM->value)]
    public function sidebar(ItemInterface $menu): void
    {
        $systemMenu = $menu->getChild('menu.top.system');

        if (! $systemMenu instanceof ItemInterface) {
            return;
        }

        // Asking for help is letting someone in: offered to those who manage
        // the company's people, and only where requests are taken at all.
        if ($this->access->can(CompanyPermission::ManageMembers) && $this->supportDesk->isEnabled()) {
            $systemMenu->addChild(
                'support',
                [
                    'label' => 'support.menu',
                    'route' => '_support',
                    'extras' => ['icon' => 'lifebuoy'],
                ],
            );
        }

        $subscription = $this->subscriptionManager->getSubscriptionFor(
            $this->companyRepository->find($this->companySelector->getCompany())
        );

        if (! $subscription instanceof Subscription) {
            return;
        }

        $systemMenu->addChild(
            'billing',
            [
                'label' => 'saas.menu.billing',
                'route' => 'billing_index',
                'extras' => ['icon' => 'receipt-2'],
            ],
        );
    }
}
