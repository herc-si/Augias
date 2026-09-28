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

namespace Augias\SaasBundle\Tests\Payment;

use Augias\SaasBundle\Payment\PaymentIntegrationSelector;
use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\StripeIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Integration\LemonSqueezy;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use UnexpectedValueException;

#[CoversClass(PaymentIntegrationSelector::class)]
final class PaymentIntegrationSelectorTest extends TestCase
{
    public function testStripeIsUsedWhenChosen(): void
    {
        $lemonSqueezy = $this->createMock(LemonSqueezy::class);
        $lemonSqueezy->expects(self::never())->method('getCustomerPortalUrl');

        $selector = new PaymentIntegrationSelector('stripe', $this->stripe(), $lemonSqueezy);

        self::assertSame('https://billing.stripe.com/p/session/1', $selector->getCustomerPortalUrl(new Subscription()->setSubscriptionId('sub_1')));
    }

    public function testLemonSqueezyStaysAvailable(): void
    {
        $subscription = new Subscription()->setSubscriptionId('ls_1');
        $lemonSqueezy = $this->createMock(LemonSqueezy::class);
        $lemonSqueezy->expects(self::once())->method('getCustomerPortalUrl')->with($subscription)->willReturn('https://ls.test/portal');

        self::assertSame('https://ls.test/portal', new PaymentIntegrationSelector('lemon_squeezy', $this->stripe(), $lemonSqueezy)->getCustomerPortalUrl($subscription));
    }

    public function testAnUnknownProviderIsNamed(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('"paddle"');

        new PaymentIntegrationSelector('paddle', $this->stripe(), $this->createStub(LemonSqueezy::class))->resume(new Subscription()->setPlan(new Plan()));
    }

    private function stripe(): StripeIntegration
    {
        $client = new MockHttpClient([
            new JsonMockResponse(['id' => 'sub_1', 'customer' => 'cus_1']),
            new JsonMockResponse(['url' => 'https://billing.stripe.com/p/session/1']),
        ], 'https://api.stripe.com/v1/');

        return new StripeIntegration(new StripeApi($client), $this->createStub(UrlGeneratorInterface::class), new MockClock(), 'saas_payment_success');
    }
}
