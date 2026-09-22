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

use Augias\CoreBundle\Menu\MainMenu;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\Test\SaasKernel;
use Knp\Menu\ItemInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\PlatformBundle\Menu\Provider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_keys;

/**
 * A hosted deployment lists the access log; see SidebarMenuTest for the
 * self-hosted side, where it is deliberately absent.
 *
 * The decision is made from the `app_mode` parameter the kernel resolves, so
 * the only way to assert it is to boot a kernel in that mode — which is what
 * the `saas-kernel` group is for.
 */
#[Group('saas-kernel')]
#[CoversClass(MainMenu::class)]
final class AccessLogMenuEntryTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheAccessLogIsListedOnAHostedDeployment(): void
    {
        $provider = self::getContainer()->get(Provider::class);
        self::assertInstanceOf(Provider::class, $provider);

        $section = $provider->get('sidebar')->getChild('menu.top.system');

        self::assertInstanceOf(ItemInterface::class, $section);
        self::assertContains('menu.top.access_log', array_keys($section->getChildren()));
    }
}
