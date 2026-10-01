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

use Augias\CoreBundle\Entity\AnnualPlanLink;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pairs a plan with its yearly twin, so the hosted service can offer one
 * plan billed monthly or yearly.
 */
final class Version40000_42 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pair a plan with its yearly twin';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(AnnualPlanLink::TABLE_NAME);
        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('monthly_plan', 'ulid', ['notnull' => true]);
        $table->addColumn('annual_plan', 'ulid', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['monthly_plan']);
        $table->addUniqueIndex(['annual_plan']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(AnnualPlanLink::TABLE_NAME);
    }
}
