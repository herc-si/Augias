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

use Augias\UserBundle\Entity\LoginRecord;
use Augias\UserBundle\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The journal of sign-ins, one row per attempt.
 *
 * `last_login` on the user held a single moment and answered nothing else.
 * This is the table behind the account-activity page, and behind a company's
 * view of who has been getting into its books.
 *
 * The user is nullable: an attempt on an address that belongs to nobody has no
 * account to hang from, and is still worth recording. It cascades, so closing
 * an account takes its journal with it.
 */
final class Version40000_19 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record sign-in attempts so an account holder can see them';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(LoginRecord::TABLE_NAME);

        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('user_id', 'ulid', ['notnull' => false]);
        $table->addColumn('identifier', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('outcome', Types::STRING, ['length' => 32, 'notnull' => true]);
        $table->addColumn('occurred_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
        $table->addColumn('user_agent', Types::STRING, ['length' => 255, 'notnull' => false]);

        $table->setPrimaryKey(['id']);

        // One account over a span of time is how the page reads it; the second
        // index is for the purge, which asks only about age. The plain
        // user_id one is the implicit index behind the foreign key, which the
        // mapping expects and the composite one does not satisfy.
        $table->addIndex(['user_id', 'occurred_at'], 'login_record_user_time');
        $table->addIndex(['occurred_at'], 'login_record_time');
        $table->addIndex(['user_id']);

        $table->addForeignKeyConstraint(
            User::TABLE_NAME,
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(LoginRecord::TABLE_NAME);
    }
}
