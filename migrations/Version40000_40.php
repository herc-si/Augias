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

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The authenticator-app secret is 52 characters (32 random bytes in Base32),
 * and the column held 45. SQLite ignores the length, but PostgreSQL and MySQL
 * refuse the secret, so setting up an authenticator app failed on those.
 */
final class Version40000_40 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen users.totp_secret to hold an authenticator-app secret';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('users')->modifyColumn('totp_secret', ['length' => 64]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('users')->modifyColumn('totp_secret', ['length' => 45]);
    }
}
