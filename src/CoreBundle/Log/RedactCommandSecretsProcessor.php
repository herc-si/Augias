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

namespace Augias\CoreBundle\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Override;
use function is_string;
use function preg_replace;

/**
 * Keeps the value of a secret command option out of the logs.
 *
 * When a console command fails, Symfony logs its whole command line —
 * `augias:install --database-password=… --admin-password=…` included — and
 * that line reaches the terminal, the container logs and Sentry alike. Any
 * option named for a password, secret, token, key or DSN keeps its name and
 * loses its value, in the message and in the `command` context entry.
 *
 * @see \Augias\CoreBundle\Tests\Log\RedactCommandSecretsProcessorTest
 */
#[AsMonologProcessor]
final class RedactCommandSecretsProcessor implements ProcessorInterface
{
    public const string REDACTED = '[redacted]';

    private const string SECRET_OPTION = '/(--[A-Za-z0-9_-]*(?:password|secret|token|key|dsn)[A-Za-z0-9_-]*)(=|\s+)(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^\s\'"]+)/i';

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;

        if (is_string($context['command'] ?? null)) {
            $context['command'] = self::redact($context['command']);
        }

        return $record->with(message: self::redact($record->message), context: $context);
    }

    public static function redact(string $commandLine): string
    {
        return (string) preg_replace(self::SECRET_OPTION, '$1$2' . self::REDACTED, $commandLine);
    }
}
