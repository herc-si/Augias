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
use Augias\CoreBundle\Entity\OperatorAccess;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The account of who looked at a company's data, kept where the company can
 * read it.
 *
 * Nothing in this repository writes to it: a self-hosted install has no
 * operator but its owner. The table exists all the same, because the promise
 * it records is made in the application the customer was given, and a promise
 * kept only in a host's private tooling is one they cannot check.
 *
 * It also has to be mapped here rather than by whatever tooling writes it:
 * UpgradeListener synchronises the schema against the mapping on an ordinary
 * web request, and a table no loaded bundle maps is a table it drops.
 */
final class Version40000_18 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record operator access to a company, where the company can read it';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(OperatorAccess::TABLE_NAME);

        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('company_id', 'ulid', ['notnull' => true]);
        $table->addColumn('operator', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('reason', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('accessed_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);

        $table->setPrimaryKey(['id']);

        // The question this table answers is asked about one company, over a
        // span of time — "who opened my file last March" — so that is the
        // index. The plain one on company_id alone is the implicit index
        // Doctrine's mapping expects behind the foreign key: without it here,
        // the schema and the mapping disagree from the first migration on.
        $table->addIndex(['company_id', 'accessed_at'], 'operator_access_company_time');
        $table->addIndex(['company_id']);

        // The record dies with the company, which is right: once there is
        // nobody left to ask, there is nothing left to keep.
        $table->addForeignKeyConstraint(
            Company::TABLE_NAME,
            ['company_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(OperatorAccess::TABLE_NAME);
    }
}
