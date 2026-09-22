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

namespace Augias\CoreBundle\Telemetry;

use const PHP_OS_FAMILY;
use const PHP_VERSION;
use Augias\CoreBundle\AugiasCoreBundle;
use Augias\CoreBundle\ConfigWriter;
use Augias\CoreBundle\Telemetry\Message\SendTelemetryMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use function in_array;
use function is_string;
use function php_uname;
use function Sentry\captureException;
use function strtolower;

/**
 * Single entry point for emitting telemetry signals to the collector the
 * operator configured.
 *
 * There is no default collector: {@see \Augias\CoreBundle\Telemetry\Message\Handler\SendTelemetryHandler}
 * posts to AUGIAS_TELEMETRY_URL, and an installation that never named one sends
 * nothing at all.
 *
 * Fully self-guarded: every public method swallows any failure so telemetry can
 * never break the request or persistence that triggered it. No PII is ever sent
 * — only the installation's technical configuration and named, fixed-vocabulary
 * events.
 *
 * @see \Augias\CoreBundle\Tests\Telemetry\TelemetryTest
 */
final readonly class Telemetry
{
    public function __construct(
        private MessageBusInterface $bus,
        private ConfigWriter $configWriter,
        private Connection $connection,
        #[Autowire(env: 'default::AUGIAS_BUILD_ID')]
        private ?string $buildId,
        #[Autowire(env: 'bool:AUGIAS_ENABLE_TELEMETRY')]
        private bool $enableTelemetry,
        #[Autowire(env: 'AUGIAS_TELEMETRY_URL')]
        private string $telemetryUrl,
        #[Autowire(env: 'AUGIAS_INSTALL_TYPE')]
        private string $installType,
        #[Autowire(env: 'bool:default::AUGIAS_DOCKER')]
        private bool $docker,
        #[Autowire(env: 'AUGIAS_LOCALE')]
        private string $locale,
        #[Autowire(env: 'default::AUGIAS_TELEMETRY_LAST_VERSION')]
        private ?string $lastVersion,
    ) {
    }

    public function isEnabled(): bool
    {
        if ($this->buildId === null || $this->buildId === '') {
            return false;
        }

        // Nowhere to send it is a reason not to collect it. Checked here rather
        // than only in the handler so an installation with no collector does
        // not queue messages that can never be delivered.
        if ($this->telemetryUrl === '') {
            return false;
        }

        return $this->enableTelemetry;
    }

    /**
     * Emit a discrete, named lifecycle event.
     *
     * @param array<string, scalar|null> $properties
     */
    public function event(TelemetryEvent $event, array $properties = [], bool $force = false): void
    {
        if (! $force && ! $this->isEnabled()) {
            return;
        }

        try {
            $this->bus->dispatch(new SendTelemetryMessage('event', [
                'build_id' => $this->buildId,
                'app' => strtolower(AugiasCoreBundle::APP_NAME),
                'event' => $event->value,
                'properties' => $properties,
            ]));
        } catch (Throwable $e) {
            captureException($e);
            // Telemetry must never break the triggering request — swallow everything.
        }
    }

    /**
     * Emit the daily installation heartbeat, and an `update` event when the
     * application version has changed since the last ping.
     */
    public function ping(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $version = AugiasCoreBundle::VERSION;

            if (! in_array($this->lastVersion, [null, '', $version], true)) {
                $this->event(TelemetryEvent::Update, [
                    'from_version' => $this->lastVersion,
                    'to_version' => $version,
                ]);
            }

            $this->configWriter->save(['TELEMETRY_LAST_VERSION' => $version]);

            $this->bus->dispatch(new SendTelemetryMessage('ping', [
                'build_id' => $this->buildId,
                'app' => strtolower(AugiasCoreBundle::APP_NAME),
                'version' => $version,
                ...$this->buildEnvironment(),
            ]));
        } catch (Throwable $e) {
            captureException($e);
            // Telemetry must never break the triggering request — swallow everything.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEnvironment(): array
    {
        $database = $this->detectDatabase();

        return [
            'os' => $this->detectOs(),
            'os_version' => php_uname('r'),
            'php_version' => PHP_VERSION,
            'install_type' => $this->detectInstallType(),
            'properties' => [
                'locale' => $this->locale,
                'db_driver' => $database['driver'],
                'db_version' => $database['version'],
            ],
        ];
    }

    private function detectOs(): string
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Windows' => 'windows',
            'Darwin' => 'macos',
            'BSD' => 'freebsd',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    private function detectInstallType(): string
    {
        if ($this->installType !== '') {
            return $this->installType;
        }

        // The Docker images (and Helm chart) set AUGIAS_DOCKER=true.
        return $this->docker ? 'docker' : 'manual';
    }

    /**
     * Derive the database driver and server version from the Doctrine connection
     * rather than the configured URL, so no credentials are ever pulled into this
     * service or the telemetry payload.
     *
     * @return array{driver: string, version: string}
     */
    private function detectDatabase(): array
    {
        try {
            return [
                'driver' => $this->databaseDriver($this->connection->getDatabasePlatform()),
                'version' => $this->databaseVersion(),
            ];
        } catch (Throwable) {
            return ['driver' => 'unknown', 'version' => 'unknown'];
        }
    }

    private function databaseDriver(AbstractPlatform $platform): string
    {
        // MariaDBPlatform extends MySQLPlatform, so it must be checked first.
        return match (true) {
            $platform instanceof MariaDBPlatform => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform => 'pgsql',
            $platform instanceof SQLitePlatform => 'sqlite',
            $platform instanceof SQLServerPlatform => 'mssql',
            $platform instanceof OraclePlatform => 'oracle',
            $platform instanceof DB2Platform => 'db2',
            default => 'unknown',
        };
    }

    private function databaseVersion(): string
    {
        $nativeConnection = $this->connection->getNativeConnection();

        if ($nativeConnection instanceof PDO) {
            $version = $nativeConnection->getAttribute(PDO::ATTR_SERVER_VERSION);

            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        return 'unknown';
    }
}
