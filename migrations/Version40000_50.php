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

use Augias\CoreBundle\Entity\DocumentActivity;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The client can now accept or decline a quote from their link. What is kept
 * as their word is the name they typed, the time, the browser and the address
 * the answer came from.
 */
final class Version40000_50 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the address a client answered a quote from';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(DocumentActivity::TABLE_NAME)
            ->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(DocumentActivity::TABLE_NAME)->dropColumn('ip_address');
    }
}
