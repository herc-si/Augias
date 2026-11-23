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

use Augias\UserBundle\Entity\TrustedDevice;
use Augias\UserBundle\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version40000_63 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trusted devices, one row each, so their holder can see and take them back';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(TrustedDevice::TABLE_NAME);
        $table->addColumn('id', 'ulid', ['notnull' => true]);
        $table->addColumn('user_id', 'ulid', ['notnull' => true]);
        $table->addColumn('device_key', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('user_agent', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
        $table->addColumn('last_used_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->addColumn('last_ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'trusted_device_user');
        $table->addForeignKeyConstraint(User::TABLE_NAME, ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(TrustedDevice::TABLE_NAME);
    }
}
