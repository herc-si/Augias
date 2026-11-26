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

namespace Augias\SaasBundle\Tests\Payment\Stripe;

use Augias\SaasBundle\Payment\Stripe\StripeApi;
use Augias\SaasBundle\Payment\Stripe\StripeTrialCarryOver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use function array_shift;
use function parse_str;

#[CoversClass(StripeTrialCarryOver::class)]
final class StripeTrialCarryOverTest extends TestCase
{
    private const int NOW = 1_830_000_000;

    private const int LEFT = 29 * 86400;

    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<string> */
    private array $errors = [];

    public function testTheDaysLeftAreGivenBackAsATrialAfterThePeriodPaidFor(): void
    {
        $end = self::NOW + 2 * 86400;

        $result = $this->carryOver([
            $this->remote(['current_period_end' => $end]),
            new JsonMockResponse(['id' => 'sub_1', 'status' => 'trialing']),
        ]);

        self::assertSame(['id' => 'sub_1', 'status' => 'trialing'], $result);
        self::assertSame('POST', $this->requests[1]['method']);
        self::assertSame([
            'trial_end' => (string) ($end + self::LEFT),
            'proration_behavior' => 'none',
            'metadata' => [StripeTrialCarryOver::SECONDS_LEFT_KEY => '', StripeTrialCarryOver::CARRIED_KEY => '1'],
        ], $this->requests[1]['body']);
    }

    public function testNothingIsDoneLongBeforeTheRenewal(): void
    {
        self::assertNull($this->carryOver([$this->remote(['current_period_end' => self::NOW + 30 * 86400])]));
        self::assertCount(1, $this->requests);
    }

    public function testNothingIsDoneWithoutDaysLeft(): void
    {
        self::assertNull($this->carryOver([$this->remote(['current_period_end' => self::NOW + 86400, 'metadata' => []])]));
        self::assertCount(1, $this->requests);
    }

    public function testARenewalAlreadyBilledIsReportedNotExtended(): void
    {
        self::assertNull($this->carryOver([$this->remote(['current_period_end' => self::NOW - 60])]));
        self::assertCount(1, $this->requests);
        self::assertCount(1, $this->errors);
    }

    public function testPaidUntilCountsTheDaysLeft(): void
    {
        $remote = ['current_period_end' => self::NOW, 'metadata' => [StripeTrialCarryOver::SECONDS_LEFT_KEY => (string) self::LEFT]];

        self::assertSame(self::NOW + self::LEFT, StripeTrialCarryOver::paidUntil($remote)->getTimestamp());
        self::assertSame(self::NOW, StripeTrialCarryOver::paidUntil(['current_period_end' => self::NOW])->getTimestamp());
    }

    /**
     * @param array<string, mixed> $override
     */
    private function remote(array $override): JsonMockResponse
    {
        return new JsonMockResponse($override + [
            'id' => 'sub_1',
            'status' => 'active',
            'metadata' => [StripeTrialCarryOver::SECONDS_LEFT_KEY => (string) self::LEFT],
        ]);
    }

    /**
     * @param list<JsonMockResponse> $responses
     *
     * @return array<string, mixed>|null
     */
    private function carryOver(array $responses): ?array
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): JsonMockResponse {
            $body = [];
            parse_str((string) ($options['body'] ?? ''), $body);
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

            return array_shift($responses) ?? self::fail('Unexpected request ' . $method . ' ' . $url);
        }, 'https://api.stripe.com/v1/');

        $logger = new class() extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string | Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    $this->errors[] = (string) $message;
                }
            }
        };

        try {
            return new StripeTrialCarryOver(new StripeApi($client), new MockClock('@' . self::NOW), $logger)->carryOver('sub_1');
        } finally {
            $this->errors = $logger->errors;
        }
    }
}
