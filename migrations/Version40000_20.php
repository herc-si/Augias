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

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\RecordAccess;
use Augias\UserBundle\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The table behind "where have I been": one row per record opened.
 *
 * The label is stored rather than resolved later, because an invoice can be
 * deleted and a client renamed, and a journal that changes its account of the
 * past along with them says less than one that reports what was on screen.
 *
 * Both foreign keys cascade: the journal belongs to the company and to the
 * person, and outlives neither.
 */
final class Version40000_20 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which documents a person opened, so they can look back';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(RecordAccess::TABLE_NAME);

        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('company_id', 'ulid', ['notnull' => true]);
        $table->addColumn('user_id', 'ulid', ['notnull' => true]);
        $table->addColumn('kind', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('record_id', 'ulid', ['notnull' => true]);
        $table->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('opened_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);

        $table->setPrimaryKey(['id']);

        // The page reads one person's trail inside one company, over time.
        // The second index is for the purge, which asks only about age. The
        // two plain ones are the implicit indexes the mapping expects behind
        // the foreign keys, which the composite one does not satisfy.
        $table->addIndex(['company_id', 'user_id', 'opened_at'], 'record_access_user_time');
        $table->addIndex(['opened_at'], 'record_access_time');
        $table->addIndex(['company_id']);
        $table->addIndex(['user_id']);

        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint(User::TABLE_NAME, ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(RecordAccess::TABLE_NAME);
    }
}
