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

namespace Augias\AccountingBundle\Menu;

use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Enum\Menu\MenuPriority;
use Augias\CoreBundle\Icon;
use Knp\Menu\ItemInterface;
use SolidWorx\Platform\PlatformBundle\Attributes\Menu\MenuBuilder;

/**
 * Accounting in the sidebar: the books themselves, not a door to them.
 *
 * It used to be one entry leading to the accounting home page, from which the
 * book, and then the form, were two more clicks away. The daily act of a
 * micro-entrepreneur — writing down the payment that just arrived — was
 * therefore the least reachable thing in the application, and it was called
 * "new entry", which is the word of whoever keeps books for a living.
 *
 * The books a company actually keeps are decided by its regime, so they are
 * asked for rather than listed: a micro-entrepreneur selling services keeps a
 * livre des recettes and nothing else, and a purchase register in their menu
 * would be an invitation to keep a book the law does not ask of them.
 *
 * Before a regime is chosen there is nothing to list, and the section stays a
 * single link to the page that asks for one.
 */
final readonly class AccountingMenu
{
    public function __construct(
        private CurrentCompany $currentCompany,
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $registry,
    ) {
    }

    #[MenuBuilder(name: 'sidebar', priority: MenuPriority::PRIORITY_ACCOUNTING->value)]
    public function sidebar(ItemInterface $menu): void
    {
        $books = $this->books();

        if ($books === []) {
            $menu->addChild(
                'accounting.menu.main',
                [
                    'route' => '_accounting_index',
                    'extras' => ['icon' => Icon::ACCOUNTING],
                ],
            );

            return;
        }

        $section = $menu->addChild(
            'accounting.menu.main',
            [
                'extras' => ['icon' => Icon::ACCOUNTING],
            ],
        );

        $section->addChild(
            'accounting.menu.overview',
            [
                'route' => '_accounting_index',
                'extras' => ['icon' => 'chart-line'],
            ],
        );

        foreach ($books as $book) {
            $section->addChild(
                $book->translationKey(),
                [
                    'route' => '_accounting_book',
                    'routeParameters' => ['book' => $book->value],
                    'extras' => ['icon' => 'book'],
                ],
            );
        }

        $section->addChild(
            'accounting.menu.declarations',
            [
                'route' => '_accounting_declarations',
                'extras' => ['icon' => 'file-text'],
            ],
        );
    }

    /**
     * The books this company keeps, or none when the question cannot be
     * answered yet — no company in scope, or no regime chosen.
     *
     * @return list<\Augias\AccountingBundle\Enum\LedgerBook>
     */
    private function books(): array
    {
        $company = $this->currentCompany->get();

        if (! $company instanceof Company) {
            return [];
        }

        $profile = $this->profileProvider->forCompany($company);
        $regime = $this->registry->forProfile($profile);

        return $regime instanceof RegimeInterface ? $regime->books($profile) : [];
    }
}
