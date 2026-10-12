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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\InvoiceBundle\Entity\CreditNote;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version40000_56 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An electronic invoicing submission may send a credit note as well as an invoice';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceSubmission::TABLE_NAME);

        $table->modifyColumn('invoice_id', ['notnull' => false]);
        $table->addColumn('credit_note_id', 'ulid', ['notnull' => false]);
        $table->addIndex(['credit_note_id']);
        $table->addForeignKeyConstraint(CreditNote::TABLE_NAME, ['credit_note_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceSubmission::TABLE_NAME);

        foreach ($table->getForeignKeys() as $foreignKey) {
            if ($foreignKey->getLocalColumns() === ['credit_note_id']) {
                $table->removeForeignKey($foreignKey->getName());
            }
        }

        $table->dropColumn('credit_note_id');
        $table->modifyColumn('invoice_id', ['notnull' => true]);
    }
}
