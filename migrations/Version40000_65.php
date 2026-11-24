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
use Augias\McpBundle\Entity\AssistantEvent;
use Augias\UserBundle\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version40000_65 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Built-in assistant: turned on by the owner, and its journal';
    }

    public function up(Schema $schema): void
    {
        $companies = $schema->getTable(Company::TABLE_NAME);
        $companies->addColumn('assistant_enabled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $companies->addColumn('assistant_enabled_by', Types::STRING, ['length' => 180, 'notnull' => false]);

        $table = $schema->createTable(AssistantEvent::TABLE_NAME);
        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('company_id', 'ulid', ['notnull' => true]);
        $table->addColumn('user_id', 'ulid', ['notnull' => false]);
        $table->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
        $table->addColumn('tool', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('failed', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->setPrimaryKey(['id']);

        // The quota counts one company's questions of the month; the purge asks
        // only about age; the plain ones back the foreign keys.
        $table->addIndex(['company_id', 'kind', 'occurred_at'], 'assistant_events_company_kind_time');
        $table->addIndex(['occurred_at'], 'assistant_events_time');
        $table->addIndex(['company_id']);
        $table->addIndex(['user_id']);

        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint(User::TABLE_NAME, ['user_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(AssistantEvent::TABLE_NAME);

        $companies = $schema->getTable(Company::TABLE_NAME);
        $companies->dropColumn('assistant_enabled_by');
        $companies->dropColumn('assistant_enabled_at');
    }
}
