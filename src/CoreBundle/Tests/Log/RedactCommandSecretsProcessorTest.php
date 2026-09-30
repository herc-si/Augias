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

namespace Augias\CoreBundle\Tests\Log;

use Augias\CoreBundle\Log\RedactCommandSecretsProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedactCommandSecretsProcessor::class)]
final class RedactCommandSecretsProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commandLines(): iterable
    {
        // What Symfony's console error listener logs, as it logged it on 28/09/2026.
        yield 'install, quoted and bare' => [
            "augias:install --database-password=4d05c72d --admin-email='rdelory@herc-si.fr' --admin-password='mhhpwu+KQ+NOZRPq4' --locale=fr",
            "augias:install --database-password=[redacted] --admin-email='rdelory@herc-si.fr' --admin-password=[redacted] --locale=fr",
        ];
        yield 'value after a space' => ['secrets:set --api-token abc123 --verbose', 'secrets:set --api-token [redacted] --verbose'];
        yield 'double quotes with an escaped quote' => ['x --mailer-dsn="smtp://u:p\"w@host" y', 'x --mailer-dsn=[redacted] y'];
        yield 'nothing secret' => ['augias:install --database-host=db --locale=fr', 'augias:install --database-host=db --locale=fr'];
    }

    #[DataProvider('commandLines')]
    public function testSecretOptionsLoseTheirValue(string $commandLine, string $expected): void
    {
        self::assertSame($expected, RedactCommandSecretsProcessor::redact($commandLine));
    }

    public function testTheMessageAndTheCommandContextAreBothRedacted(): void
    {
        $command = 'augias:install --database-password=s3cret --locale=fr';
        $record = new LogRecord(
            new DateTimeImmutable(),
            'console',
            Level::Critical,
            'Error thrown while running command "' . $command . '". Message: "boom"',
            ['command' => $command, 'message' => 'boom'],
        );

        $redacted = new RedactCommandSecretsProcessor()($record);

        self::assertStringNotContainsString('s3cret', $redacted->message);
        self::assertSame('augias:install --database-password=[redacted] --locale=fr', $redacted->context['command']);
        self::assertSame('boom', $redacted->context['message']);
    }
}
