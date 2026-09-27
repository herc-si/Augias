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

use Augias\InvoiceBundle\Entity\Line as InvoiceLine;
use Augias\QuoteBundle\Entity\Line as QuoteLine;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives a document line the unit its quantity counts.
 *
 * The catalogue had one, but it was lost on the way to the line: "3" on an
 * invoice could be hours, days or pieces, and the electronic invoice said
 * "one" for all of them. Existing lines become plain counts, which is what
 * they were read as.
 */
final class Version40000_37 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the unit of each invoice and quote line';
    }

    public function up(Schema $schema): void
    {
        foreach ([InvoiceLine::TABLE_NAME, QuoteLine::TABLE_NAME] as $table) {
            $schema->getTable($table)->addColumn('unit', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'unit']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([InvoiceLine::TABLE_NAME, QuoteLine::TABLE_NAME] as $table) {
            $schema->getTable($table)->dropColumn('unit');
        }
    }
}
