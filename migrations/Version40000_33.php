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
 * A company being closed is reminded once, a week before it goes; the date
 * the reminder went out is kept so that the daily task sends it only once.
 */
final class Version40000_33 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember when the closure reminder went out';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(Company::TABLE_NAME)->addColumn('closure_reminded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(Company::TABLE_NAME)->dropColumn('closure_reminded_at');
    }
}
