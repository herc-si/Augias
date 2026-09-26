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

namespace Augias\CoreBundle\Doctrine\Listener;

use Augias\CoreBundle\Doctrine\ForeignKeys;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Migrations\Event\MigrationsEventArgs;
use Doctrine\Migrations\Events;

/**
 * Migrations change the schema, which SQLite does by dropping and recreating
 * tables: with foreign keys on, that would cascade into the child tables.
 * Suspended for the run — outside the per-migration transactions, where the
 * pragma would be ignored — and restored after.
 */
#[AsDoctrineListener(event: Events::onMigrationsMigrating)]
#[AsDoctrineListener(event: Events::onMigrationsMigrated)]
final class SuspendForeignKeysDuringMigrations
{
    public function onMigrationsMigrating(MigrationsEventArgs $event): void
    {
        ForeignKeys::suspend($event->getConnection());
    }

    public function onMigrationsMigrated(MigrationsEventArgs $event): void
    {
        ForeignKeys::restore($event->getConnection());
    }
}
