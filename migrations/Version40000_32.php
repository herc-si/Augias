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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Closing a company no longer deletes it on the spot: it is scheduled, and
 * the date it goes is kept on the company. None is scheduled yet.
 */
final class Version40000_32 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a company scheduled for closure goes';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(Company::TABLE_NAME)->addColumn('closes_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(Company::TABLE_NAME)->dropColumn('closes_at');
    }
}
