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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Entity\Company;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\TaxBundle\Entity\InvoiceTax;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The credit note — the *avoir* — and the columns that hang off it.
 *
 * Lines live in the existing `invoice_lines` table rather than one of their
 * own: that table already carries the discriminator that separates invoice
 * lines from recurring invoice lines, and sharing it is what lets a credit note
 * line keep its taxes, quantity and totals without a second mapping. The same
 * reasoning puts the document's tax summary in `invoice_tax`, beside the ones
 * for invoices, quotes and recurring invoices.
 *
 * `credited_invoice_id` is nullable and drops to NULL rather than cascading: a
 * credit can answer to no invoice at all (a rebate, a gesture), and deleting an
 * invoice must never quietly take its correction with it.
 */
final class Version40000_12 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add credit notes, their lines and their tax summary';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(CreditNote::TABLE_NAME);
        $table->addColumn('id', UlidType::NAME);
        $table->addColumn('credit_note_id', Types::STRING, ['length' => 255]);
        $table->addColumn('uuid', Types::STRING, ['length' => 36]);
        $table->addColumn('status', Types::STRING, ['length' => 25]);
        $table->addColumn('reason', Types::STRING, ['length' => 25, 'notnull' => false]);
        $table->addColumn('credit_note_date', Types::DATE_IMMUTABLE);
        $table->addColumn('issued_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('total_amount', Types::BIGINT);
        $table->addColumn('baseTotal_amount', Types::BIGINT);
        $table->addColumn('tax_amount', Types::BIGINT);
        $table->addColumn('withholding_amount', Types::BIGINT, ['default' => 0]);
        $table->addColumn('payable_amount', Types::BIGINT, ['default' => 0]);
        $table->addColumn('discount_valueMoney_amount', Types::BIGINT);
        $table->addColumn('discount_value_percentage', Types::FLOAT, ['notnull' => false]);
        $table->addColumn('discount_type', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('terms', Types::TEXT, ['notnull' => false]);
        $table->addColumn('notes', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created', Types::DATETIME_MUTABLE);
        $table->addColumn('updated', Types::DATETIME_MUTABLE);
        $table->addColumn('company_id', UlidType::NAME);
        $table->addColumn('client_id', UlidType::NAME);
        $table->addColumn('credited_invoice_id', UlidType::NAME, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['company_id']);
        $table->addIndex(['client_id']);
        $table->addIndex(['credited_invoice_id'], 'idx_credit_note_credited');
        $table->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint(Client::TABLE_NAME, ['client_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint(Invoice::TABLE_NAME, ['credited_invoice_id'], ['id'], ['onDelete' => 'SET NULL']);

        $contacts = $schema->createTable('credit_note_contact');
        $contacts->addColumn('credit_note_id', UlidType::NAME);
        $contacts->addColumn('contact_id', UlidType::NAME);
        $contacts->setPrimaryKey(['credit_note_id', 'contact_id']);
        $contacts->addIndex(['credit_note_id']);
        $contacts->addIndex(['contact_id']);
        $contacts->addForeignKeyConstraint(CreditNote::TABLE_NAME, ['credit_note_id'], ['id'], ['onDelete' => 'CASCADE']);
        $contacts->addForeignKeyConstraint(Contact::TABLE_NAME, ['contact_id'], ['id'], ['onDelete' => 'CASCADE']);

        $lines = $schema->getTable(Line::TABLE_NAME);
        $lines->addColumn('credit_note_id', UlidType::NAME, ['notnull' => false]);
        $lines->addIndex(['credit_note_id']);
        $lines->addForeignKeyConstraint(CreditNote::TABLE_NAME, ['credit_note_id'], ['id'], ['onDelete' => 'CASCADE']);

        $taxes = $schema->getTable(InvoiceTax::TABLE_NAME);
        $taxes->addColumn('credit_note_id', UlidType::NAME, ['notnull' => false]);
        $taxes->addIndex(['credit_note_id']);
        $taxes->addForeignKeyConstraint(CreditNote::TABLE_NAME, ['credit_note_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        foreach ([Line::TABLE_NAME, InvoiceTax::TABLE_NAME] as $name) {
            $table = $schema->getTable($name);

            foreach ($table->getForeignKeys() as $key => $foreignKey) {
                if (CreditNote::TABLE_NAME === $foreignKey->getForeignTableName()) {
                    $table->removeForeignKey((string) $key);
                }
            }

            $table->dropColumn('credit_note_id');
        }

        $schema->dropTable('credit_note_contact');
        $schema->dropTable(CreditNote::TABLE_NAME);
    }
}
