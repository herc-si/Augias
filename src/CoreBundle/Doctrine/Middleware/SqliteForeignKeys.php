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

namespace Augias\CoreBundle\Doctrine\Middleware;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;
use function in_array;

/**
 * Turns SQLite's foreign keys on for every connection.
 *
 * The schema declares them — ON DELETE CASCADE, SET NULL — but SQLite enforces
 * none unless each connection asks, and Doctrine does not. Every onDelete was
 * therefore ignored: a deleted quote left its invoice pointing at nothing, and
 * the invoice's page answered 500. MySQL and PostgreSQL keep them already.
 *
 * Suspended while the schema changes — see {@see \Augias\CoreBundle\Doctrine\ForeignKeys}.
 *
 * Next to the driver (a higher priority is wrapped first): the pragma must
 * reach the connection when it is opened, before anything starts a
 * transaction on it — the test suite's shared connection (priority 100)
 * does, and inside one the pragma is ignored.
 */
#[AsMiddleware(priority: 200)]
final class SqliteForeignKeys implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[SensitiveParameter]
                array $params,
            ): Connection {
                $connection = parent::connect($params);

                if (in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
