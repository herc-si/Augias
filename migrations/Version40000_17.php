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

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\CoreBundle\Entity\Company;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a book entry hold the document behind it.
 *
 * The books already named the supporting document; this is where the document
 * itself gets a row. The file lives on disk — see AttachmentStorage — and what
 * is kept here is what it takes to serve it back and to notice if it ever
 * stopped being the file that was uploaded.
 *
 * The foreign key cascades so the row cannot outlive its entry, but the file on
 * disk is removed by an entity listener rather than by the database: a cascade
 * the ORM never hears about would leave the volume full of documents belonging
 * to entries that no longer exist.
 */
final class Version40000_17 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the supporting documents attached to book entries';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(EntryAttachment::TABLE_NAME);

        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('entry_id', 'ulid', ['notnull' => true]);
        $table->addColumn('company_id', 'ulid', ['notnull' => true]);
        $table->addColumn('filename', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('mime_type', Types::STRING, ['length' => 127, 'notnull' => true]);
        $table->addColumn('size', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('storage_path', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('created', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('updated', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['entry_id'], 'idx_attachment_entry');

        $table->addForeignKeyConstraint(
            LedgerEntry::TABLE_NAME,
            ['entry_id'],
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
        $schema->dropTable(EntryAttachment::TABLE_NAME);
    }
}
