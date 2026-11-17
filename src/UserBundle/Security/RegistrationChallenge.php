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

namespace Augias\UserBundle\Security;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use function hash;
use function hash_hmac;
use function random_int;

/**
 * The proof of work asked of a browser before it may create an account
 * (ALTCHA, MIT, run entirely here): the server hands out a signed challenge,
 * the browser spends a second or two of computation on it, and the form
 * carries the answer back. Free for a person, costly for a script creating
 * accounts by the thousand — and, unlike a hosted captcha, nothing is sent to
 * a third party and no cookie is set.
 *
 * A challenge is signed with a key derived from the application secret, so
 * there is nothing to configure, expires after ten minutes, and is accepted
 * once: a solved answer cannot be replayed for a second account.
 *
 * @see \Augias\UserBundle\Tests\Security\RegistrationChallengeTest
 */
final readonly class RegistrationChallenge
{
    public const int LIFETIME_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private string $secret,
        #[Autowire('%augias.registration_challenge.cost%')]
        private int $cost = 3000,
        #[Autowire('%augias.registration_challenge.counter_min%')]
        private int $counterMin = 4000,
        #[Autowire('%augias.registration_challenge.counter_max%')]
        private int $counterMax = 8000,
    ) {
    }

    /**
     * @return array<string, mixed> the challenge as the widget expects it ({parameters, signature})
     */
    public function create(): array
    {
        return $this->altcha()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: $this->cost,
            counter: random_int($this->counterMin, $this->counterMax),
            expiresAt: $this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS,
        ))->toArray();
    }

    /**
     * Whether the widget's answer (the base64 payload it posts) solves a
     * challenge this server signed, still valid and not used before.
     */
    public function verify(#[SensitiveParameter] ?string $payload): bool
    {
        if (null === $payload || '' === $payload) {
            return false;
        }

        try {
            $decoded = Payload::fromBase64($payload);
            $result = $this->altcha()->verifySolution(new VerifySolutionOptions(
                algorithm: new Pbkdf2(),
                payload: $decoded,
            ));
        } catch (Throwable) {
            // A payload that does not even parse.
            return false;
        }

        if (! $result->verified || null === $decoded->challenge->signature) {
            return false;
        }

        // Once only. Keyed on the challenge's signature, not on the payload:
        // the same answer re-encoded differently is still the same challenge.
        $used = $this->cache->getItem('augias_registration_challenge_' . hash('sha256', $decoded->challenge->signature));

        if ($used->isHit()) {
            return false;
        }

        $this->cache->save($used->set(true)->expiresAfter(self::LIFETIME_SECONDS));

        return true;
    }

    private function altcha(): Altcha
    {
        return new Altcha(
            hmacSignatureSecret: hash_hmac('sha256', 'augias-registration-challenge', $this->secret),
            hmacKeySignatureSecret: hash_hmac('sha256', 'augias-registration-challenge-key', $this->secret),
        );
    }
}
