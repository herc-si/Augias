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
use Augias\QuoteBundle\Entity\Quote;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deposit invoices: an invoice can say which quote it bills a deposit on.
 *
 * A column of its own beside `quote_id`, which stays unique — one final
 * invoice per quote — while a quote can have several deposits billed first.
 * Deleting the quote leaves its deposit invoices in place, unlinked.
 *
 * Numbered 61: 60 is the closure archive, in a pull request of its own.
 */
final class Version40000_61 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link a deposit invoice to the quote it bills a deposit on';
    }

    public function up(Schema $schema): void
    {
        $invoices = $schema->getTable(Invoice::TABLE_NAME);

        $invoices->addColumn('deposit_quote_id', 'ulid', ['notnull' => false]);
        $invoices->addIndex(['deposit_quote_id']);
        $invoices->addForeignKeyConstraint(Quote::TABLE_NAME, ['deposit_quote_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        $invoices = $schema->getTable(Invoice::TABLE_NAME);

        foreach ($invoices->getForeignKeys() as $foreignKey) {
            if (['deposit_quote_id'] === $foreignKey->getLocalColumns()) {
                $invoices->removeForeignKey($foreignKey->getName());
            }
        }

        foreach ($invoices->getIndexes() as $index) {
            if (['deposit_quote_id'] === $index->getColumns()) {
                $invoices->dropIndex($index->getName());
            }
        }

        $invoices->dropColumn('deposit_quote_id');
    }
}
