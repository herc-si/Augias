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

namespace Augias\SaasBundle\Payment\Stripe;

use const JSON_THROW_ON_ERROR;
use Override;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use function abs;
use function ctype_digit;
use function explode;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function json_decode;
use function trim;

/**
 * Checks that a webhook comes from Stripe, then hands its event on.
 *
 * Stripe signs `<timestamp>.<body>` with the endpoint's secret and sends the
 * result in the Stripe-Signature header, `t=<timestamp>,v1=<signature>`. The
 * timestamp keeps a captured request from being replayed later.
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\StripeRequestParserTest
 */
final class StripeRequestParser extends AbstractRequestParser
{
    /** Stripe's own libraries allow the same five minutes. */
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    #[Override]
    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new IsJsonRequestMatcher(),
            new MethodRequestMatcher('POST'),
        ]);
    }

    #[Override]
    protected function doParse(Request $request, #[SensitiveParameter] string $secret): RemoteEvent
    {
        if ($secret === '') {
            throw new RejectWebhookException(Response::HTTP_SERVICE_UNAVAILABLE, 'No Stripe webhook secret is configured.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $request->headers->get('Stripe-Signature')) as $part) {
            [$key, $value] = explode('=', trim($part), 2) + [1 => ''];

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            throw new RejectWebhookException(Response::HTTP_UNAUTHORIZED, 'Missing or stale Stripe signature timestamp.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = $valid || hash_equals($expected, $signature);
        }

        if (! $valid) {
            throw new RejectWebhookException(Response::HTTP_UNAUTHORIZED, 'Invalid Stripe signature.');
        }

        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            throw new RejectWebhookException(Response::HTTP_BAD_REQUEST, 'Not a Stripe event.');
        }

        /** @var array<string, mixed> $payload */
        return new RemoteEvent($payload['type'], $payload['id'], $payload);
    }
}
