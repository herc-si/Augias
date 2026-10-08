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
use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\UserBundle\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A document's history: sent to whom and when, opened by the client when,
 * moved from one status to another by whom.
 *
 * The company cascades, the history belongs to it. The person who did
 * something is set to null when their account goes: the document was still
 * sent that day.
 */
final class Version40000_48 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record what happened to a quote, an invoice or a credit note: sent, opened by the client, status';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(DocumentActivity::TABLE_NAME);

        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('company_id', 'ulid', ['notnull' => true]);
        $table->addColumn('kind', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('record_id', 'ulid', ['notnull' => true]);
        $table->addColumn('type', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('user_id', 'ulid', ['notnull' => false]);
        $table->addColumn('detail', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('recipients', Types::JSON, ['notnull' => false]);
        $table->addColumn('user_agent', Types::STRING, ['length' => 255, 'notnull' => false]);

        $table->setPrimaryKey(['id']);

        // The document's page reads its own history, over time. The plain
        // indexes are the ones the mapping expects behind the foreign keys.
        $table->addIndex(['company_id', 'kind', 'record_id', 'occurred_at'], 'document_activity_document');
        $table->addIndex(['company_id']);
        $table->addIndex(['user_id']);

        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint(User::TABLE_NAME, ['user_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(DocumentActivity::TABLE_NAME);
    }
}
