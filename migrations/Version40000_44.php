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
use Augias\CoreBundle\Entity\CompanyCoverage;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Companies paid for by another one's subscription (an agency plan covering
 * the companies its owner manages).
 *
 * Created everywhere like the rest of the core; it stays empty where nothing
 * is sold.
 */
final class Version40000_44 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let one subscription cover several companies of the same owner';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(CompanyCoverage::TABLE_NAME);
        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('covered_id', 'ulid', ['notnull' => true]);
        $table->addColumn('host_id', 'ulid', ['notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['covered_id']);
        $table->addIndex(['host_id']);
        // The cover goes with either company.
        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['covered_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['host_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(CompanyCoverage::TABLE_NAME);
    }
}
