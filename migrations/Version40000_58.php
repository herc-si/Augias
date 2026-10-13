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
use Augias\ElectronicInvoicingBundle\Entity\ElectronicDeclaration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The e-reporting declarations the platform sent the tax administration,
 * copied every hour so the company sees them and hears of a rejection.
 */
final class Version40000_58 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the e-reporting declarations sent to the tax administration';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(ElectronicDeclaration::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('provider', Types::STRING, ['length' => 255]);
        $table->addColumn('external_id', Types::STRING, ['length' => 64]);
        $table->addColumn('kind', Types::STRING, ['length' => 20]);
        $table->addColumn('period_start', Types::DATE_IMMUTABLE);
        $table->addColumn('period_end', Types::DATE_IMMUTABLE);
        $table->addColumn('status', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('status_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['company_id', 'provider', 'external_id'], 'unique_declaration');
        $table->addIndex(['company_id']);
        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(ElectronicDeclaration::TABLE_NAME);
    }
}
