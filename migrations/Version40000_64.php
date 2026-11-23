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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version40000_64 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sign-in journal: how each sign-in got past the second factor';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(LoginRecord::TABLE_NAME)
            ->addColumn('second_factor', Types::STRING, ['length' => 16, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(LoginRecord::TABLE_NAME)->dropColumn('second_factor');
    }
}
