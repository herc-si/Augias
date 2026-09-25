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

use Augias\InvoiceBundle\Entity\Invoice;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets an invoice say when the goods were delivered, when that is not its
 * own date. Empty on every invoice already issued: they were taken to be
 * delivered on their date, and still are.
 */
final class Version40000_26 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a delivery date to invoices';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(Invoice::TABLE_NAME)->addColumn('delivery_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(Invoice::TABLE_NAME)->dropColumn('delivery_date');
    }
}
