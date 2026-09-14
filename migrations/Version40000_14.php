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
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The journal underneath the client's credit balance.
 *
 * The balance is one number per client and cannot say where it came from. This
 * records each use of a credit note — set against an invoice, or refunded — so
 * the question "which invoice consumed which credit note" has an answer.
 *
 * `invoice_id` is nullable (a refund is set against nothing) and drops to NULL
 * rather than cascading: deleting an invoice must not erase the record of a
 * credit having been used.
 */
final class Version40000_14 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the credit note allocation journal';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(CreditNoteAllocation::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('kind', Types::STRING, ['length' => 20]);
        $table->addColumn('amount', Types::BIGINT);
        $table->addColumn('allocated_on', Types::DATE_IMMUTABLE);
        $table->addColumn('notes', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->addColumn('credit_note_id', UlidType::NAME);
        $table->addColumn('invoice_id', UlidType::NAME, ['notnull' => false]);
        $table->addColumn('company_id', UlidType::NAME);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['company_id']);
        $table->addIndex(['credit_note_id'], 'idx_allocation_credit_note');
        $table->addIndex(['invoice_id'], 'idx_allocation_invoice');
        $table->addForeignKeyConstraint(CreditNote::TABLE_NAME, ['credit_note_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint(Invoice::TABLE_NAME, ['invoice_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(CreditNoteAllocation::TABLE_NAME);
    }
}
