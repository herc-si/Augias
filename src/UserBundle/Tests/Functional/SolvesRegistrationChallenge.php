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

namespace Augias\UserBundle\Tests\Functional;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use function json_decode;

/**
 * Signs up the way a browser does: the registration form wants the answer to
 * a proof-of-work challenge (RegistrationChallenge), which the widget fetches
 * and solves. The test environment makes it cheap, not optional.
 */
trait SolvesRegistrationChallenge
{
    /**
     * @param array<string, string> $fields the form's own fields, `register[...]`
     */
    private function signUp(KernelBrowser $client, array $fields, ?string $proofOfWork = null, bool $solve = true): void
    {
        $proofOfWork ??= $solve ? $this->solvedChallenge($client) : null;

        $crawler = $client->request(Request::METHOD_GET, '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form($fields);

        // The widget posts its answer under the captcha field's name; the
        // field itself is not in the page.
        $values = $form->getPhpValues();

        if (null !== $proofOfWork) {
            $values['register']['captcha'] = $proofOfWork;
        }

        $client->request($form->getMethod(), $form->getUri(), $values);
    }

    /**
     * Does what the widget does: asks for a challenge, solves it, encodes the answer.
     */
    private function solvedChallenge(KernelBrowser $client): string
    {
        $client->request(Request::METHOD_GET, '/register/challenge');
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $challenge = Challenge::fromArray($data);
        $solution = new Altcha('unused')->solveChallenge(new SolveChallengeOptions(algorithm: new Pbkdf2(), challenge: $challenge));

        self::assertNotNull($solution);

        return new Payload($challenge, $solution)->toBase64();
    }
}
