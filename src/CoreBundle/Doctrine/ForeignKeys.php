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

namespace Augias\CoreBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * SQLite keeps foreign keys only when asked, per connection, and Augias asks
 * (see {@see Middleware\SqliteForeignKeys}). Changing the schema is the one
 * time it must not: SQLite alters a table by copying it, dropping it and
 * creating it again, and dropping a parent with the keys on deletes every
 * child row that cascades from it. Migrations and the installer suspend them.
 *
 * The pragma is ignored inside a transaction, so both calls are made outside
 * one. Anything but SQLite is left alone.
 *
 * @see \Augias\CoreBundle\Tests\Doctrine\ForeignKeysTest
 */
final class ForeignKeys
{
    public static function suspend(Connection $connection): void
    {
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
        }
    }

    public static function restore(Connection $connection): void
    {
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
    }
}
