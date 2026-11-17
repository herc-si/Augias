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

namespace Augias\UserBundle\Tests\Security;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Augias\UserBundle\Security\RegistrationChallenge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RegistrationChallenge::class)]
final class RegistrationChallengeTest extends TestCase
{
    public function testASolvedChallengeIsAcceptedOnceOnly(): void
    {
        $challenge = $this->service();
        $payload = $this->solve($challenge->create());

        self::assertTrue($challenge->verify($payload));
        self::assertFalse($challenge->verify($payload), 'the same answer for a second account');
    }

    public function testWhatIsNotASolvedChallengeOfThisServerIsRefused(): void
    {
        $challenge = $this->service();

        self::assertFalse($challenge->verify(null));
        self::assertFalse($challenge->verify(''));
        self::assertFalse($challenge->verify('not base64 at all'));

        // Signed by another server (another secret).
        self::assertFalse($challenge->verify($this->solve($this->service('another secret')->create())));

        // Signed here, but with an answer that was not computed: the derived key is the proof.
        $solved = Payload::fromBase64($this->solve($challenge->create()));
        $forged = new Payload($solved->challenge, new Solution(counter: $solved->solution->counter, derivedKey: str_repeat('0', strlen($solved->solution->derivedKey))));
        self::assertFalse($challenge->verify($forged->toBase64()));

        // A solved challenge whose parameters were altered afterwards no longer matches its signature.
        $altered = Payload::fromBase64($this->solve($challenge->create()))->toArray();
        $altered['challenge']['parameters']['expiresAt'] = time() + 86400;
        self::assertFalse($challenge->verify(Payload::fromArray($altered)->toBase64()));
    }

    public function testAnExpiredChallengeIsRefused(): void
    {
        // Issued an hour ago, valid ten minutes.
        $old = $this->service('secret', new MockClock('-1 hour'));

        self::assertFalse($old->verify($this->solve($old->create())));
    }

    private function service(string $secret = 'secret', ?MockClock $clock = null): RegistrationChallenge
    {
        return new RegistrationChallenge(new ArrayAdapter(), $clock ?? new MockClock(), $secret, 1, 1, 5);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function solve(array $data): string
    {
        $challenge = Challenge::fromArray($data);
        $solution = new Altcha('unused')->solveChallenge(new SolveChallengeOptions(algorithm: new Pbkdf2(), challenge: $challenge));

        self::assertNotNull($solution);

        return new Payload($challenge, $solution)->toBase64();
    }
}
