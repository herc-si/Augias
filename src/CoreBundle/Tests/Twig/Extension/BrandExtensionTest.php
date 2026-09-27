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

namespace Augias\CoreBundle\Tests\Twig\Extension;

use Augias\CoreBundle\Config\DesignConfigProvider;
use Augias\CoreBundle\Twig\Extension\BrandExtension;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\SystemConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use SolidWorx\Toggler\ToggleInterface;

#[CoversClass(BrandExtension::class)]
final class BrandExtensionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @return iterable<string, array{bool, string, string, bool, bool}>
     */
    public static function poweredBy(): iterable
    {
        // saas, self-hosted box, hosted switch, custom_branding on the plan => hidden
        yield 'self-hosted, box ticked' => [false, '1', '0', false, true];
        yield 'self-hosted, box left' => [false, '0', '1', true, false];
        yield 'hosted, paid option on and switched on' => [true, '0', '1', true, true];
        yield 'hosted, switched on without the option' => [true, '0', '1', false, false];
        yield 'hosted, the self-hosted box counts for nothing' => [true, '1', '0', true, false];
    }

    #[DataProvider('poweredBy')]
    public function testWhoDecidesToHidePoweredBy(bool $saas, string $selfHostedBox, string $hostedSwitch, bool $customBranding, bool $hidden): void
    {
        $config = M::mock(SystemConfig::class);
        $config->allows('get')->with(DesignConfigProvider::HIDE_POWERED_BY)->andReturn($selfHostedBox);
        $config->allows('get')->with(BrandExtension::HOSTED_HIDE_POWERED_BY)->andReturn($hostedSwitch);

        $toggle = M::mock(ToggleInterface::class);
        $toggle->allows('isActive')->with('saas_enabled')->andReturn($saas);

        $gate = M::mock(FeatureGate::class);
        $gate->allows('isEnabled')->with(Feature::CustomBranding->value)->andReturn($customBranding);

        self::assertSame($hidden, new BrandExtension($config, $toggle, $gate)->hidePoweredBy());
    }
}
