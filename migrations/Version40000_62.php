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

namespace DoctrineMigrations;

use Augias\CoreBundle\Entity\ErrorRecord;
use Augias\CoreBundle\Entity\ScheduledTaskRun;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version40000_62 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Error journal and last run of each scheduled task, read by the operator console';
    }

    public function up(Schema $schema): void
    {
        $errors = $schema->createTable(ErrorRecord::TABLE_NAME);
        $errors->addColumn('id', 'ulid');
        $errors->addColumn('fingerprint', 'string', ['length' => 40]);
        $errors->addColumn('level', 'string', ['length' => 16]);
        $errors->addColumn('source', 'string', ['length' => 16]);
        $errors->addColumn('exception_class', 'string', ['length' => 255, 'notnull' => false]);
        $errors->addColumn('message', 'text');
        $errors->addColumn('file', 'string', ['length' => 255, 'notnull' => false]);
        $errors->addColumn('line', 'integer', ['notnull' => false]);
        $errors->addColumn('occurrences', 'integer');
        $errors->addColumn('first_seen_at', 'datetime_immutable');
        $errors->addColumn('last_seen_at', 'datetime_immutable');
        $errors->setPrimaryKey(['id']);
        $errors->addUniqueIndex(['fingerprint']);
        $errors->addIndex(['last_seen_at'], 'error_records_last_seen');

        $runs = $schema->createTable(ScheduledTaskRun::TABLE_NAME);
        $runs->addColumn('id', 'ulid');
        $runs->addColumn('task', 'string', ['length' => 100]);
        $runs->addColumn('status', 'string', ['length' => 16]);
        $runs->addColumn('last_started_at', 'datetime_immutable');
        $runs->addColumn('last_finished_at', 'datetime_immutable', ['notnull' => false]);
        $runs->addColumn('duration_ms', 'integer', ['notnull' => false]);
        $runs->addColumn('last_error', 'text', ['notnull' => false]);
        $runs->addColumn('next_expected_at', 'datetime_immutable', ['notnull' => false]);
        $runs->addColumn('runs', 'integer');
        $runs->setPrimaryKey(['id']);
        $runs->addUniqueIndex(['task']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(ScheduledTaskRun::TABLE_NAME);
        $schema->dropTable(ErrorRecord::TABLE_NAME);
    }
}
