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

use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Twig\Extension\BrandExtension;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\SystemConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;

#[CoversClass(BrandExtension::class)]
final class BrandExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool, bool}>
     */
    public static function poweredBy(): iterable
    {
        // stored switch, custom_branding on the plan => hidden
        yield 'switched on, option paid for' => ['1', true, true];
        yield 'switched on, option no longer on the plan' => ['1', false, false];
        yield 'switched off, option paid for' => ['0', true, false];
        yield 'never set' => [null, true, false];
    }

    #[DataProvider('poweredBy')]
    public function testThePoweredByMentionFollowsThePaidOption(?string $switch, bool $customBranding, bool $hidden): void
    {
        $config = $this->createStub(SystemConfig::class);
        $config->method('get')->willReturnCallback(static fn (string $key): ?string => $key === BrandExtension::HIDE_POWERED_BY ? $switch : null);

        $gate = $this->createStub(FeatureGate::class);
        $gate->method('isEnabled')->willReturnCallback(static fn (string $key): bool => $key === Feature::CustomBranding->value && $customBranding);

        self::assertSame($hidden, new BrandExtension($config, $gate, new CompanyBankDetails($config))->hidePoweredBy());
    }
}
