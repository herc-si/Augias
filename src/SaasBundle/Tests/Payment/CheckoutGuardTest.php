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

use Augias\SaasBundle\Payment\CheckoutGuard;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;

#[CoversClass(CheckoutGuard::class)]
final class CheckoutGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{SubscriptionStatus, ?string, string, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'never billed: a trial without a card' => [SubscriptionStatus::TRIAL, null, '+10 days', null];
        yield 'never billed: pending' => [SubscriptionStatus::PENDING, null, '+10 days', null];
        yield 'paid' => [SubscriptionStatus::ACTIVE, 'sub_1', '+10 days', 'saas.flash.checkout_already_subscribed'];
        yield 'trialing with a card' => [SubscriptionStatus::TRIAL, 'sub_1', '+10 days', 'saas.flash.checkout_already_subscribed'];
        yield 'paused' => [SubscriptionStatus::PAUSED, 'sub_1', '+10 days', 'saas.flash.checkout_already_subscribed'];
        yield 'late' => [SubscriptionStatus::PAST_DUE, 'sub_1', '+10 days', 'saas.flash.checkout_settle_first'];
        yield 'unpaid' => [SubscriptionStatus::UNPAID, 'sub_1', '+10 days', 'saas.flash.checkout_settle_first'];
        yield 'cancelled, still running' => [SubscriptionStatus::CANCELLED, 'sub_1', '+10 days', 'saas.flash.checkout_still_running'];
        yield 'cancelled and ended' => [SubscriptionStatus::CANCELLED, 'sub_1', '-1 day', null];
        yield 'expired' => [SubscriptionStatus::EXPIRED, 'sub_1', '-1 day', null];
    }

    #[DataProvider('cases')]
    public function testOnlyASubscriptionNotBilledByTheProviderMayCheckOut(SubscriptionStatus $status, ?string $providerId, string $ends, ?string $expected): void
    {
        $now = new DateTimeImmutable('2026-11-23 12:00');
        $subscription = new Subscription()->setStatus($status)->setEndDate($now->modify($ends))->setSubscriptionId($providerId);

        self::assertSame($expected, CheckoutGuard::refusal($subscription, $now));
    }
}
