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

namespace Augias\CoreBundle\Tests\Menu;

use Augias\CoreBundle\Menu\PrestationMenu;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Knp\Menu\ItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use SolidWorx\Platform\PlatformBundle\Menu\Provider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_keys;

/**
 * The sidebar is assembled from menu builders spread across eight bundles,
 * ordered only by a priority number each one declares on its own. Nothing about
 * that arrangement fails loudly when it goes wrong: a mistyped priority
 * reshuffles the navigation, and a builder that runs before the section it
 * attaches to silently drops its entry back to the top level. Both are
 * invisible in a unit test of any single bundle, which is why the assembled
 * result is asserted here.
 *
 * @see \Augias\CoreBundle\Enum\Menu\MenuPriority
 */
#[CoversClass(PrestationMenu::class)]
final class SidebarMenuTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testTopLevelOrder(): void
    {
        self::assertSame(
            [
                'dashboard.title',
                'client.menu.main',
                PrestationMenu::SECTION,
                'catalog.menu.main',
                'payment.menu.main',
                'accounting.menu.main',
                'menu.top.system',
            ],
            $this->names($this->sidebar()),
        );
    }

    public function testPrestationGroupsTheBillingDocuments(): void
    {
        $section = $this->sidebar()->getChild(PrestationMenu::SECTION);

        self::assertInstanceOf(ItemInterface::class, $section);
        self::assertSame(
            ['quote.menu.main', 'invoice.menu.main', 'bill.menu.main', 'credit_note.menu.main'],
            $this->names($section),
        );
    }

    /**
     * The three documents live under Prestation and nowhere else — the whole
     * point of the section is that they stopped being top-level entries.
     */
    public function testBillingDocumentsAreNotAlsoAtTheTopLevel(): void
    {
        $top = $this->names($this->sidebar());

        foreach (['quote.menu.main', 'invoice.menu.main', 'bill.menu.main'] as $name) {
            self::assertNotContains($name, $top);
        }
    }

    /**
     * The section is a grouping, not a destination: it must open a dropdown
     * rather than navigate somewhere of its own.
     */
    public function testPrestationSectionHasNoRouteOfItsOwn(): void
    {
        $section = $this->sidebar()->getChild(PrestationMenu::SECTION);

        self::assertInstanceOf(ItemInterface::class, $section);
        self::assertNull($section->getUri());
        self::assertTrue($section->hasChildren());
    }

    private function sidebar(): ItemInterface
    {
        $provider = self::getContainer()->get(Provider::class);
        self::assertInstanceOf(Provider::class, $provider);

        return $provider->get('sidebar');
    }

    /**
     * @return list<string>
     */
    private function names(ItemInterface $item): array
    {
        return array_keys($item->getChildren());
    }
}
