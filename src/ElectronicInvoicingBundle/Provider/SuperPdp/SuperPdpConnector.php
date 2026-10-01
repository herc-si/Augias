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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\SuperPdpAuthorization;
use Augias\ElectronicInvoicingBundle\Repository\SuperPdpAuthorizationRepository;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;
use function array_slice;
use function base64_encode;
use function ctype_digit;
use function hash;
use function is_array;
use function is_int;
use function is_string;
use function preg_replace;
use function random_bytes;
use function rtrim;
use function strlen;
use function strtr;
use function substr;

/**
 * Connects a company's SUPER PDP account to the deployment's OAuth
 * application — the authorization code flow, with PKCE:
 *
 * 1. {@see start()} sends the user to SUPER PDP, their e-mail and SIREN filled
 *    in, with a one-time `state` and a code challenge kept in their session;
 * 2. there they sign in or sign up, have the company verified, and agree;
 * 3. SUPER PDP sends them back with a code, which {@see complete()} trades
 *    for the company's tokens and records on the provider setting.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp\SuperPdpConnectorTest
 */
final readonly class SuperPdpConnector
{
    public const string CALLBACK_ROUTE = '_einvoicing_super_pdp_callback';

    private const string SESSION_KEY = '_einvoicing_super_pdp_connect';

    /**
     * Connections started and not finished, kept in the session at most:
     * enough for a few tabs, not a growing pile.
     */
    private const int PENDING = 5;

    /**
     * How long SUPER PDP's sign-up may take — company verification included —
     * before the code is no longer accepted here.
     */
    private const int LIFETIME = 3600;

    public function __construct(
        private SuperPdpClient $client,
        private SuperPdpApplication $application,
        private SuperPdpAuthorizationRepository $authorizations,
        private TaxIdentifierRepository $taxIdentifiers,
        private TokenCipher $cipher,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether this deployment has an application to connect to.
     */
    public function isAvailable(): bool
    {
        return $this->application->isConfigured();
    }

    /**
     * Where to send the user to connect $setting's account.
     */
    public function start(ElectronicInvoiceProviderSetting $setting, SessionInterface $session, ?string $email): string
    {
        $state = self::random();
        $verifier = self::random() . self::random();

        $pending = $this->pending($session);
        $pending[$state] = [
            'setting' => (string) $setting->getId(),
            'verifier' => $verifier,
            'at' => $this->clock->now()->getTimestamp(),
        ];
        $session->set(self::SESSION_KEY, array_slice($pending, -self::PENDING, preserve_keys: true));

        return $this->client->authorizationUrl(
            $this->application->clientId,
            $this->redirectUri(),
            $state,
            self::base64Url(hash('sha256', $verifier, true)),
            $email,
            $this->siren($setting),
        );
    }

    /**
     * Trades the code for the company's tokens, and points $setting at them in
     * place of whatever it held before — pasted credentials, or an older
     * connection, which is revoked. The caller flushes — before using the
     * connection: its tokens are read back from the database.
     *
     * @throws SuperPdpConnectionFailed
     */
    public function complete(SessionInterface $session, string $state, string $code): ElectronicInvoiceProviderSetting
    {
        $pending = $this->pending($session);
        $started = $pending[$state] ?? null;

        // Spent whatever happens next: a state works once.
        unset($pending[$state]);
        $session->set(self::SESSION_KEY, $pending);

        if (null === $started || $started['at'] + self::LIFETIME < $this->clock->now()->getTimestamp()) {
            throw new SuperPdpConnectionFailed('einvoicing.super_pdp.connect.expired');
        }

        // Looked up with the company filter on: a setting of another company
        // than the one the user is in is not found.
        $setting = Ulid::isValid($started['setting']) ? $this->entityManager->find(ElectronicInvoiceProviderSetting::class, Ulid::fromString($started['setting'])) : null;

        if (! $setting instanceof ElectronicInvoiceProviderSetting) {
            throw new SuperPdpConnectionFailed('einvoicing.super_pdp.connect.expired');
        }

        $now = $this->clock->now();

        try {
            $tokens = $this->client->exchangeAuthorizationCode(
                $this->application->clientId,
                $this->application->clientSecret,
                $code,
                $this->redirectUri(),
                $started['verifier'],
                $now,
            );
        } catch (SuperPdpApiException $e) {
            $this->logger->error('SUPER PDP did not exchange the authorization code.', ['exception' => $e]);

            throw new SuperPdpConnectionFailed('einvoicing.super_pdp.connect.failed', $e);
        }

        $this->forgetAuthorization($setting);

        $authorization = new SuperPdpAuthorization(
            $this->cipher->encrypt($tokens->refreshToken),
            $this->cipher->encrypt($tokens->accessToken),
            $tokens->expiresAt,
            $now,
        );
        $authorization->setCompany($setting->getCompany());
        $this->entityManager->persist($authorization);

        $setting->setSettings([SuperPdpAccessTokens::AUTHORIZATION => (string) $authorization->getId()]);

        return $setting;
    }

    /**
     * Revokes and removes the connection $setting holds, if it holds one —
     * before the setting goes, or when a new connection replaces it. The
     * caller flushes.
     */
    public function forgetAuthorization(ElectronicInvoiceProviderSetting $setting): void
    {
        $id = $setting->getSettings()[SuperPdpAccessTokens::AUTHORIZATION] ?? null;
        $authorization = is_string($id) && Ulid::isValid($id) ? $this->authorizations->find(Ulid::fromString($id)) : null;

        if (! $authorization instanceof SuperPdpAuthorization) {
            return;
        }

        $sealed = $authorization->getRefreshToken();
        $refreshToken = null === $sealed ? null : $this->cipher->decrypt($sealed);

        if (null !== $refreshToken && $this->application->isConfigured()) {
            try {
                $this->client->revokeToken($this->application->clientId, $this->application->clientSecret, $refreshToken);
            } catch (SuperPdpApiException $e) {
                // Forgotten here all the same: the company can still withdraw
                // its consent on SUPER PDP.
                $this->logger->warning('Could not revoke the SUPER PDP refresh token.', ['exception' => $e]);
            }
        }

        $this->entityManager->remove($authorization);
    }

    private function redirectUri(): string
    {
        return $this->urlGenerator->generate(self::CALLBACK_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * The company's SIREN, to fill in on SUPER PDP — only a real one: a
     * wrong number filled in is worse than none.
     */
    private function siren(ElectronicInvoiceProviderSetting $setting): ?string
    {
        $identifiers = $this->taxIdentifiers->findCompanyIdentifiers($setting->getCompany()->getId());

        foreach ([TaxIdentifierType::SIREN, TaxIdentifierType::SIRET] as $label) {
            foreach ($identifiers as $identifier) {
                if (! $identifier instanceof TaxIdentifier || $identifier->getLabel() !== $label) {
                    continue;
                }

                $digits = (string) preg_replace('/\D/', '', (string) $identifier->getValue());

                if (strlen($digits) >= 9 && ctype_digit($digits) && self::luhn(substr($digits, 0, 9))) {
                    return substr($digits, 0, 9);
                }
            }
        }

        return null;
    }

    /**
     * A SIREN's last digit is a Luhn check digit.
     */
    private static function luhn(string $digits): bool
    {
        $sum = 0;

        for ($i = 0, $length = strlen($digits); $i < $length; ++$i) {
            $digit = (int) $digits[$length - 1 - $i];

            if (1 === $i % 2) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
        }

        return 0 === $sum % 10;
    }

    /**
     * @return array<string, array{setting: string, verifier: string, at: int}>
     */
    private function pending(SessionInterface $session): array
    {
        $pending = $session->get(self::SESSION_KEY, []);
        $valid = [];

        foreach (is_array($pending) ? $pending : [] as $state => $started) {
            if (is_string($state) && is_array($started) && is_string($started['setting'] ?? null) && is_string($started['verifier'] ?? null) && is_int($started['at'] ?? null)) {
                $valid[$state] = ['setting' => $started['setting'], 'verifier' => $started['verifier'], 'at' => $started['at']];
            }
        }

        return $valid;
    }

    private static function random(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
