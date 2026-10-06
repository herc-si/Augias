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

use Augias\ElectronicInvoicingBundle\Entity\SuperPdpAuthorization;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoicingProblem;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoicingAlerts;
use Augias\ElectronicInvoicingBundle\Repository\SuperPdpAuthorizationRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;
use function is_string;

/**
 * The access token to call SUPER PDP with, for a company's provider settings
 * — whichever way the company gave Augias access:
 *
 * - its own OAuth application (`client_id`, `client_secret`): a fresh token
 *   for every call, as it always was;
 * - its account connected to the deployment's application
 *   (`authorization`, the id of a {@see \Augias\ElectronicInvoicingBundle\Entity\SuperPdpAuthorization}):
 *   the stored access token while it lasts, then a refresh.
 *
 * A refresh token works once (OAuth 2.1 rotates it), so only one process
 * refreshes at a time — the others wait for its result — and the new one is
 * written before anything else happens. Lose it, and the company has to
 * connect again.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp\SuperPdpAccessTokensTest
 */
final readonly class SuperPdpAccessTokens
{
    /**
     * The settings key that holds the authorization's id.
     */
    public const string AUTHORIZATION = 'authorization';

    /**
     * An access token this close to its end is refreshed rather than used:
     * it could expire on the way.
     */
    private const string MARGIN = '+60 seconds';

    /**
     * How long a process may hold the right to refresh.
     */
    private const string CLAIM = '+30 seconds';

    /**
     * How long to wait for another process's refresh, in tenths of a second.
     */
    private const int WAIT = 50;

    public function __construct(
        private SuperPdpClient $client,
        private SuperPdpApplication $application,
        private SuperPdpAuthorizationRepository $authorizations,
        private TokenCipher $cipher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ElectronicInvoicingAlerts $alerts,
    ) {
    }

    /**
     * Whether the settings hold any way in: own credentials, or a connection.
     *
     * @param array<string, mixed> $config
     */
    public function hasCredentials(array $config): bool
    {
        return null !== $this->authorizationId($config) || null !== $this->ownCredentials($config);
    }

    /**
     * Whether the company connected its account rather than pasting
     * credentials.
     *
     * @param array<string, mixed> $config
     */
    public function isConnection(array $config): bool
    {
        return null !== $this->authorizationId($config);
    }

    /**
     * Whether the connection still holds: SUPER PDP has not refused its
     * refresh token. Says nothing about the company's verification.
     *
     * @param array<string, mixed> $config
     */
    public function isConnected(array $config): bool
    {
        $id = $this->authorizationId($config);

        return null !== $id && null !== ($this->authorizations->readTokens($id)['refreshToken'] ?? null);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws SuperPdpApiException
     */
    public function accessToken(array $config): string
    {
        $id = $this->authorizationId($config);

        if (null !== $id) {
            return $this->connected($id);
        }

        $credentials = $this->ownCredentials($config);

        if (null === $credentials) {
            throw new SuperPdpApiException('einvoicing.provider.super_pdp.missing_credentials');
        }

        return $this->client->getAccessToken(...$credentials);
    }

    /**
     * @throws SuperPdpApiException
     */
    private function connected(Ulid $id): string
    {
        $token = $this->stillValid($id);

        if (null !== $token) {
            return $token;
        }

        $now = $this->clock->now();

        if (! $this->authorizations->claimRefresh($id, $now, $now->modify(self::CLAIM))) {
            return $this->refreshedByAnother($id);
        }

        $sealed = $this->authorizations->readTokens($id)['refreshToken'] ?? null;
        $refreshToken = null === $sealed ? null : $this->cipher->decrypt($sealed);

        if (null === $refreshToken || ! $this->application->isConfigured()) {
            // Sealed under another application secret, or the application is
            // gone from the configuration: nothing will refresh it.
            $this->disconnect($id, $now);

            throw self::disconnected();
        }

        try {
            $tokens = $this->client->refreshTokens($this->application->clientId, $this->application->clientSecret, $refreshToken, $now);
        } catch (SuperPdpApiException $e) {
            if ($e->isInvalidGrant()) {
                $this->logger->warning('SUPER PDP refused the refresh token: the company has to connect again.', ['authorization' => (string) $id]);
                $this->disconnect($id, $now);

                throw self::disconnected($e);
            }

            // Not reached, or refused for another reason: the refresh token
            // was not spent, and the next attempt can use it.
            $this->authorizations->releaseRefresh($id);

            throw $e;
        }

        $this->authorizations->storeTokens(
            $id,
            $this->cipher->encrypt($tokens->refreshToken),
            $this->cipher->encrypt($tokens->accessToken),
            $tokens->expiresAt,
        );

        return $tokens->accessToken;
    }

    /**
     * @throws SuperPdpApiException
     */
    private function refreshedByAnother(Ulid $id): string
    {
        for ($i = 0; $i < self::WAIT; ++$i) {
            $this->clock->sleep(0.1);

            $token = $this->stillValid($id);

            if (null !== $token) {
                return $token;
            }

            if (null === ($this->authorizations->readTokens($id)['refreshToken'] ?? null)) {
                throw self::disconnected();
            }
        }

        throw new SuperPdpApiException('Another process is refreshing the SUPER PDP tokens; try again in a moment.');
    }

    /**
     * @throws SuperPdpApiException
     */
    private function stillValid(Ulid $id): ?string
    {
        $tokens = $this->authorizations->readTokens($id);

        if (null === $tokens) {
            throw self::disconnected();
        }

        if (null === $tokens['refreshToken']) {
            throw self::disconnected();
        }

        if (null === $tokens['accessToken'] || null === $tokens['expiresAt'] || $tokens['expiresAt'] <= $this->clock->now()->modify(self::MARGIN)) {
            return null;
        }

        return $this->cipher->decrypt($tokens['accessToken']);
    }

    /**
     * Nothing will refresh the tokens any more: forgotten, and the company
     * told — until it connects again, nothing is sent or received.
     */
    private function disconnect(Ulid $id, DateTimeImmutable $now): void
    {
        $this->authorizations->markDisconnected($id, $now);

        $authorization = $this->authorizations->find($id);

        if ($authorization instanceof SuperPdpAuthorization) {
            $this->alerts->raise($authorization->getCompany(), ElectronicInvoicingProblem::Disconnected);
        }
    }

    private static function disconnected(?SuperPdpApiException $previous = null): SuperPdpApiException
    {
        return new SuperPdpApiException('einvoicing.provider.super_pdp.disconnected', previous: $previous, httpStatus: 401, oauthError: 'invalid_grant');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function authorizationId(array $config): ?Ulid
    {
        $id = $config[self::AUTHORIZATION] ?? null;

        return is_string($id) && Ulid::isValid($id) ? Ulid::fromString($id) : null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: string, 1: string}|null
     */
    private function ownCredentials(array $config): ?array
    {
        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;

        if (! is_string($clientId) || '' === $clientId || ! is_string($clientSecret) || '' === $clientSecret) {
            return null;
        }

        return [$clientId, $clientSecret];
    }
}
