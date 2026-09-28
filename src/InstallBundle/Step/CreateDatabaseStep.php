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

namespace Augias\InstallBundle\Step;

use Augias\InstallBundle\DTO\Installation;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\Persistence\ManagerRegistry;
use Generator;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function str_replace;

/**
 * @see \Augias\InstallBundle\Tests\Step\CreateDatabaseStepTest
 */
#[AsTaggedItem('Creating database', priority: 20)]
final readonly class CreateDatabaseStep implements InstallationStepInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public static function priority(): int
    {
        return 20;
    }

    public function execute(Installation $installationData, ?callable $callback = null): Generator
    {
        $connection = $this->doctrine->getConnection();
        $params = $connection->getParams();

        if ($params['driver'] !== 'pdo_sqlite') {
            $dbName = $params['dbname'];
            unset($params['dbname']);
        } else {
            $dbName = str_replace($this->projectDir . '/', './', $params['path']);
        }

        $tmpConnection = DriverManager::getConnection(
            $params,
            $connection->getConfiguration(),
        );

        if ($params['driver'] === 'pdo_sqlite') {
            // Force the underlying connection to open, which creates the SQLite
            // file (Connection::connect() is protected in DBAL 4).
            $tmpConnection->getNativeConnection();
            $tmpConnection->close();
        } else {
            $schemaManager = $tmpConnection->createSchemaManager();
            if (! self::databaseExists($schemaManager, $dbName)) {
                $schemaManager->createDatabase($dbName);
            }
        }

        if ($callback !== null) {
            yield from $callback(sprintf('Database "%s" created', $dbName));
        }
    }

    /**
     * Since DBAL 4.3 the schema manager lists databases as UnqualifiedName
     * objects, not strings: compared with the name as a string, a database
     * that exists was never found, and the step tried to create it again —
     * which fails on any server where the database is created beforehand,
     * as the official PostgreSQL and MySQL images do.
     *
     * @param AbstractSchemaManager<AbstractPlatform> $schemaManager
     */
    public static function databaseExists(AbstractSchemaManager $schemaManager, string $dbName): bool
    {
        foreach ($schemaManager->introspectDatabaseNames() as $name) {
            if ($name->getIdentifier()->getValue() === $dbName) {
                return true;
            }
        }

        return false;
    }

    public static function getLabel(): string
    {
        return 'Creating database';
    }
}
