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

namespace Augias\CoreBundle\Menu;

use Augias\CoreBundle\Enum\Menu\MenuPriority;
use Augias\CoreBundle\Feature\UpgradePromptProvider;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;

class MainMenu
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly FeatureGate $featureGate,
        private readonly UpgradePromptProvider $upgradePromptProvider,
    ) {
    }

    #[MenuBuilder(name: 'sidebar', priority: MenuPriority::PRIORITY_SYSTEM->value)]
    public function sidebar(ItemInterface $menu): void
    {
        $section = $menu->addChild(
            'menu.top.system',
            [
                'extras' => [
                    'icon' => 'device-laptop',
                ],
            ],
        );

        self::integrations($section);
        $this->tax($section);
        self::paymentMethods($section);
        self::categories($section);
        self::einvoicing($section);
        self::api($section);
        self::users($section);
        self::settings($section);
        $this->addCustomFields($section);
    }

    public static function user(ItemInterface $item, User $user): ItemInterface
    {
        $username = $user->getUserIdentifier() . ' <b class="caret"></b>';

        return $item->addChild(
            'user',
            [
                'uri' => '#',
                'allow_safe_labels' => true,
                'label' => $username,
                'extras' => [
                    'safe_label' => true,
                    'icon' => 'user',
                ],
            ],
        );
    }

    public static function profile(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.profile',
            ['route' => '_profile', 'extras' => ['icon' => 'user']],
        );
    }

    public static function api(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.api',
            ['route' => '_api_keys_index', 'extras' => ['icon' => 'shield-lock']],
        );
    }

    public static function logout(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.logout',
            [
                'route' => '_logout',
                // `power-off` is not a Tabler icon and never was, so this
                // entry has been rendering without one.
                'extras' => ['icon' => 'power'],
            ],
        );
    }

    public static function system(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.system',
            [
                'uri' => '#',
                'allow_safe_labels' => true,
                'extras' => [
                    'safe_label' => true,
                    'icon' => 'device-laptop',
                ],
            ],
        );
    }

    public static function settings(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.settings',
            [
                'route' => '_settings',
                'extras' => ['icon' => 'settings'],
            ],
        );
    }

    public static function integrations(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.integrations',
            [
                'route' => '_notification_integration',
                'extras' => ['icon' => 'apps'],
            ],
        );
    }

    public static function paymentMethods(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'payment.menu.methods',
            [
                'route' => '_payment_settings_index',
                'extras' => ['icon' => 'receipt'],
            ],
        );
    }

    /**
     * Skipped entirely for a company outside the scope of VAT: a
     * micro-entrepreneur in franchise en base has no rates to manage, and the
     * screen only invites confusion.
     */
    public function tax(ItemInterface $item): ?ItemInterface
    {
        if ($this->systemConfig->isVatExempt()) {
            return null;
        }

        return $item->addChild(
            'menu.top.tax',
            [
                'route' => '_tax_rates',
                'extras' => ['icon' => 'tax'],
            ],
        );
    }

    /**
     * Purchases and the catalogue used to have a category screen each, sitting
     * side by side here with the same icon and near-identical labels. They are
     * one list now - see CoreBundle\\Entity\\Category.
     */
    public static function categories(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.categories',
            [
                'route' => '_categories_index',
                'extras' => ['icon' => 'category'],
            ],
        );
    }

    public static function einvoicing(ItemInterface $item): ItemInterface
    {
        // Configures how outgoing invoices are sent. Received invoices are a
        // separate concern reached from the purchase-invoices page, via
        // ElectronicInvoicingBundle\Twig\Components\PendingReceipts.
        return $item->addChild(
            'menu.top.einvoicing',
            [
                'route' => '_einvoicing_providers',
                'extras' => ['icon' => 'building-broadcast-tower'],
            ],
        );
    }

    public static function users(ItemInterface $item): ItemInterface
    {
        return $item->addChild(
            'menu.top.users',
            [
                'route' => '_users_list',
                'extras' => ['icon' => 'users'],
            ],
        );
    }

    public function addCustomFields(ItemInterface $item): ItemInterface
    {
        $extras = ['icon' => 'forms'];

        if (! $this->featureGate->isEnabled(Feature::CustomFields->value)) {
            $planLabel = $this->upgradePromptProvider->menuLabel(Feature::CustomFields->value);

            if ($planLabel !== null) {
                $extras['plan_label'] = $planLabel;
            }
        }

        return $item->addChild(
            'menu.top.custom_fields',
            [
                'route' => '_settings_custom_fields',
                'extras' => $extras,
            ],
        );
    }
}
