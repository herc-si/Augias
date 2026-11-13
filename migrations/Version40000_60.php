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
use Augias\CoreBundle\Entity\ExportJob;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * A closing company is handed an archive of what it has to keep, without
 * having to ask: an export says what it was made for and when it was first
 * taken, and a company when its closure was scheduled.
 */
final class Version40000_60 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The archive a closing company is handed';
    }

    public function up(Schema $schema): void
    {
        $jobs = $schema->getTable(ExportJob::TABLE_NAME);
        $jobs->addColumn('purpose', Types::STRING, ['length' => 20, 'default' => 'requested']);
        $jobs->addColumn('downloaded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        $schema->getTable(Company::TABLE_NAME)->addColumn('closure_scheduled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $jobs = $schema->getTable(ExportJob::TABLE_NAME);
        $jobs->dropColumn('purpose');
        $jobs->dropColumn('downloaded_at');

        $schema->getTable(Company::TABLE_NAME)->dropColumn('closure_scheduled_at');
    }
}
