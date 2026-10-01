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
use Augias\CoreBundle\Entity\SupportRequest;
use Augias\CoreBundle\Entity\SupportSettings;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A company asking the people who run a hosted deployment to come and look,
 * and the terms on which they take such requests.
 *
 * Created everywhere, used only on a hosted deployment: a self-hosted install
 * never has a settings row, so the feature stays off — the same reasoning as
 * the access log beside it, whose shape is public because the promise it
 * records is made to the customer.
 */
final class Version40000_41 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a company ask for help and open its door for a set time';
    }

    public function up(Schema $schema): void
    {
        $settings = $schema->createTable(SupportSettings::TABLE_NAME);
        $settings->addColumn('id', 'ulid', ['notnull' => true]);
        $settings->addColumn('enabled', Types::BOOLEAN, ['notnull' => true]);
        $settings->addColumn('provider_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $settings->addColumn('max_hours', Types::INTEGER, ['notnull' => true]);
        $settings->addColumn('notify_email', Types::STRING, ['length' => 255, 'notnull' => false]);
        $settings->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $settings->setPrimaryKey(['id']);

        $request = $schema->createTable(SupportRequest::TABLE_NAME);
        $request->addColumn('id', 'ulid', ['notnull' => true]);
        $request->addColumn('company_id', 'ulid', ['notnull' => true]);
        $request->addColumn('requested_by', Types::STRING, ['length' => 255, 'notnull' => true]);
        $request->addColumn('message', Types::TEXT, ['notnull' => true]);
        $request->addColumn('hours', Types::INTEGER, ['notnull' => true]);
        $request->addColumn('requested_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $request->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $request->addColumn('status', Types::STRING, ['length' => 20, 'notnull' => true]);
        $request->addColumn('operator', Types::STRING, ['length' => 255, 'notnull' => false]);
        $request->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $request->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $request->addColumn('ended_by', Types::STRING, ['length' => 255, 'notnull' => false]);
        $request->addColumn('note', Types::TEXT, ['notnull' => false]);
        $request->setPrimaryKey(['id']);
        $request->addIndex(['company_id', 'requested_at'], 'support_request_company_time');
        // The implicit index Doctrine's mapping expects behind the foreign key;
        // the composite one above does not stand in for it.
        $request->addIndex(['company_id']);
        // The request is the company's, and goes with it.
        $request->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);

        $schema->getTable(OperatorAccess::TABLE_NAME)
            ->addColumn('detail', Types::STRING, ['length' => 255, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(OperatorAccess::TABLE_NAME)->dropColumn('detail');
        $schema->dropTable(SupportRequest::TABLE_NAME);
        $schema->dropTable(SupportSettings::TABLE_NAME);
    }
}
