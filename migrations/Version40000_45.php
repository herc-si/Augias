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
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lines of text only (a heading over the lines that follow), and the order
 * of the lines, which can now be changed.
 *
 * Every line already written gets position 0: ordered by position then id,
 * the documents keep the order they had.
 */
final class Version40000_45 extends AbstractMigration
{
    private const array TABLES = ['invoice_lines', 'quote_lines'];

    public function getDescription(): string
    {
        return 'Let a document carry lines of text only, and order its lines';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $name) {
            $table = $schema->getTable($name);
            $table->addColumn('note', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $name) {
            $table = $schema->getTable($name);
            $table->dropColumn('note');
            $table->dropColumn('position');
        }
    }
}
