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

namespace Augias\PaymentBundle\Tests\Twig\Components;

use Augias\PaymentBundle\Factory\PaymentFactories;
use Augias\PaymentBundle\Gateway\GatewayMetadataProvider;
use Augias\PaymentBundle\Repository\PaymentMethodRepository;
use Augias\PaymentBundle\Twig\Components\PaymentSettings;
use Augias\SaasBundle\Feature\Feature;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(PaymentSettings::class)]
final class PaymentSettingsGateTest extends TestCase
{
    /**
     * The payment settings page is closed to a plan without online payments,
     * but its live action could be posted to directly (30/09/2026).
     */
    public function testAPlanWithoutOnlinePaymentsCannotSaveAPaymentMethod(): void
    {
        $gate = $this->createMock(FeatureGate::class);
        $gate->expects(self::once())->method('isEnabled')->with(Feature::OnlinePayments->value)->willReturn(false);

        $component = new PaymentSettings(
            new PaymentFactories(),
            $this->createStub(PaymentMethodRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new RequestStack(),
            new GatewayMetadataProvider(),
            $gate,
        );

        $this->expectException(AccessDeniedException::class);

        $component->save();
    }
}
