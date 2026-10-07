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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmissionEvent;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UlidType;

final class Version40000_46 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep every step of a sent electronic invoice, not just the latest';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(ElectronicInvoiceSubmissionEvent::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('submission_id', UlidType::NAME);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('provider_event_id', Types::STRING, ['length' => 64]);
        $table->addColumn('status_code', Types::STRING, ['length' => 64]);
        $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('reason', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('note', Types::TEXT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['submission_id', 'provider_event_id'], 'einvoicing_submission_event_once');
        $table->addIndex(['company_id']);
        $table->addForeignKeyConstraint(ElectronicInvoiceSubmission::TABLE_NAME, ['submission_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('companies', ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(ElectronicInvoiceSubmissionEvent::TABLE_NAME);
    }
}
