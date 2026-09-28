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

use const JSON_THROW_ON_ERROR;
use Augias\SaasBundle\Payment\Stripe\StripeRequestParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use function hash_hmac;
use function json_encode;

#[CoversClass(StripeRequestParser::class)]
final class StripeRequestParserTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    private const int NOW = 1_790_590_000;

    public function testASignedEventIsHandedOn(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_1']]], JSON_THROW_ON_ERROR);

        $event = $this->parser()->parse($this->request($body, 't=' . self::NOW . ',v1=0000,v1=' . $this->sign(self::NOW, $body)), self::SECRET);

        self::assertNotNull($event);
        self::assertSame('customer.subscription.updated', $event->getName());
        self::assertSame('evt_1', $event->getId());
        self::assertSame('sub_1', $event->getPayload()['data']['object']['id']);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function refused(): iterable
    {
        yield 'signed with another secret' => ['whsec_other', self::NOW];
        yield 'signed ten minutes ago' => [self::SECRET, self::NOW - 600];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(string $secret, int $signedAt): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'customer.subscription.updated'], JSON_THROW_ON_ERROR);

        $this->expectException(RejectWebhookException::class);

        $this->parser()->parse($this->request($body, 't=' . $signedAt . ',v1=' . hash_hmac('sha256', $signedAt . '.' . $body, $secret)), self::SECRET);
    }

    public function testATamperedBodyIsRefused(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'customer.subscription.updated'], JSON_THROW_ON_ERROR);
        $signature = $this->sign(self::NOW, $body);

        $this->expectException(RejectWebhookException::class);

        $this->parser()->parse($this->request($body . ' ', 't=' . self::NOW . ',v1=' . $signature), self::SECRET);
    }

    public function testNothingIsAcceptedWithoutASecret(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'customer.subscription.updated'], JSON_THROW_ON_ERROR);

        $this->expectException(RejectWebhookException::class);

        $this->parser()->parse($this->request($body, 't=' . self::NOW . ',v1=' . hash_hmac('sha256', self::NOW . '.' . $body, '')), '');
    }

    private function parser(): StripeRequestParser
    {
        return new StripeRequestParser(new MockClock('@' . self::NOW));
    }

    private function sign(int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);
    }

    private function request(string $body, string $signature): Request
    {
        return Request::create('/webhook/stripe', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $body);
    }
}
