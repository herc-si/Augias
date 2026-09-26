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
 * A company closes either because its owner asked or because its hosted
 * subscription ended; the two are told different things and called off
 * differently. Closures already scheduled were all asked for, which is what
 * an empty reason reads as.
 */
final class Version40000_34 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember why a company is closing';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(Company::TABLE_NAME)->addColumn('closure_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(Company::TABLE_NAME)->dropColumn('closure_reason');
    }
}
