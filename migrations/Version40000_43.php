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
use Augias\ElectronicInvoicingBundle\Entity\SuperPdpAuthorization;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A company's SUPER PDP account connected to the deployment's OAuth
 * application, instead of credentials pasted in: the tokens, sealed.
 *
 * Created everywhere, used only where the deployment registered an
 * application with SUPER PDP; elsewhere the table stays empty.
 */
final class Version40000_43 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a company connect its SUPER PDP account instead of pasting credentials';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(SuperPdpAuthorization::TABLE_NAME);
        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('company_id', 'ulid', ['notnull' => true]);
        $table->addColumn('refresh_token', Types::TEXT, ['notnull' => false]);
        $table->addColumn('access_token', Types::TEXT, ['notnull' => false]);
        $table->addColumn('access_token_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('refreshing_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('connected_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('disconnected_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['company_id']);
        // The connection is the company's, and goes with it.
        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(SuperPdpAuthorization::TABLE_NAME);
    }
}
