<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use DateTimeImmutable;
use function is_int;
use function is_string;
use function sprintf;

/**
 * What SUPER PDP's token endpoint hands back for the authorization code flow.
 *
 * The refresh token is good for a year and rotates on every use: the one
 * given here replaces the one spent to get it, which no longer works. The
 * access token lasts about half an hour.
 */
final readonly class SuperPdpTokens
{
    /**
     * When the lifetime is not given: SUPER PDP's documented half hour.
     */
    private const int DEFAULT_LIFETIME = 1800;

    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @param array<string, mixed> $response
     *
     * @throws SuperPdpApiException
     */
    public static function fromResponse(array $response, DateTimeImmutable $now): self
    {
        $accessToken = $response['access_token'] ?? null;
        $refreshToken = $response['refresh_token'] ?? null;

        if (! is_string($accessToken) || '' === $accessToken || ! is_string($refreshToken) || '' === $refreshToken) {
            throw new SuperPdpApiException('SUPER PDP did not return the expected tokens.');
        }

        $lifetime = is_int($response['expires_in'] ?? null) ? $response['expires_in'] : self::DEFAULT_LIFETIME;

        return new self($accessToken, $refreshToken, $now->modify(sprintf('+%d seconds', $lifetime)));
    }
}
