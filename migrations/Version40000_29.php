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
use Augias\ElectronicInvoicingBundle\Entity\ElectronicReport;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * Remembers every sale and payment reported for e-reporting, so nothing is
 * reported twice and a failed attempt is tried again.
 */
final class Version40000_29 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the sales and payments reported for e-reporting';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(ElectronicReport::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('kind', Types::STRING, ['length' => 20]);
        $table->addColumn('source_id', UlidType::NAME);
        $table->addColumn('provider', Types::STRING, ['length' => 255]);
        $table->addColumn('success', Types::BOOLEAN);
        $table->addColumn('external_reference', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('message', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['company_id', 'kind', 'source_id'], 'unique_report_source');
        $table->addIndex(['company_id']);
        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(ElectronicReport::TABLE_NAME);
    }
}
