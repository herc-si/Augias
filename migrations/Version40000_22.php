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
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Augias\InvoiceBundle\Entity\Line;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a disbursement line hold the supplier's document behind it.
 *
 * Same shape as the documents behind book entries (Version40000_17), and the
 * same split: the file on disk, what it takes to serve it back here. The
 * foreign key cascades so a row cannot outlive its line, and the file itself
 * goes through an entity listener, which a database cascade would bypass.
 */
final class Version40000_22 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the supplier documents behind disbursement lines';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(DisbursementReceipt::TABLE_NAME);

        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('line_id', 'ulid', ['notnull' => true]);
        $table->addColumn('company_id', 'ulid', ['notnull' => true]);
        $table->addColumn('filename', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('mime_type', Types::STRING, ['length' => 127, 'notnull' => true]);
        $table->addColumn('size', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('storage_path', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('created', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('updated', Types::DATETIME_IMMUTABLE, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['line_id'], 'idx_disbursement_receipt_line');
        $table->addIndex(['company_id'], 'IDX_B75BBCA6979B1AD6');

        $table->addForeignKeyConstraint(
            Line::TABLE_NAME,
            ['line_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );

        $table->addForeignKeyConstraint(
            Company::TABLE_NAME,
            ['company_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(DisbursementReceipt::TABLE_NAME);
    }
}
