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

namespace Augias\CoreBundle\Tests\Watch;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use DoctrineMigrations\Version40000_62;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A real database for the journal's tests: a SQLite file (ErrorJournal opens
 * a connection of its own, which an in-memory database would not share),
 * with the tables exactly as the migration creates them.
 */
trait JournalDatabase
{
    private string $databaseFile;

    private function journalDatabase(): Connection
    {
        if (! Type::hasType(UlidType::NAME)) {
            Type::addType(UlidType::NAME, UlidType::class);
        }

        $this->databaseFile = sys_get_temp_dir() . '/augias-journal-' . bin2hex(random_bytes(6)) . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databaseFile]);

        $schema = new Schema();
        (new Version40000_62($connection, new NullLogger()))->up($schema);

        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }

        return $connection;
    }

    private function dropJournalDatabase(): void
    {
        new Filesystem()->remove($this->databaseFile);
    }
}
