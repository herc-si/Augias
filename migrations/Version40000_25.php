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

use Augias\BillBundle\Entity\Bill;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * When the VAT on a document falls due, on both sides of the books.
 *
 * On the documents the company issues, whether its option for VAT on debits
 * applied — frozen when the document goes out. Null for everything already
 * issued, which reads as "on payment": that is what it was.
 *
 * On supplier bills, what was bought and whether the supplier opted for
 * debits, which together decide when the VAT is deductible. Every bill
 * already recorded becomes services bought from a supplier on receipts —
 * deductible on payment, as Augias has always treated it.
 */
final class Version40000_25 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when VAT falls due on issued documents and supplier bills';
    }

    public function up(Schema $schema): void
    {
        foreach ([Invoice::TABLE_NAME, RecurringInvoice::TABLE_NAME, CreditNote::TABLE_NAME] as $table) {
            $schema->getTable($table)->addColumn('vat_on_debits', Types::BOOLEAN, ['notnull' => false]);
        }

        $bills = $schema->getTable(Bill::TABLE_NAME);
        $bills->addColumn('supply_type', Types::STRING, ['length' => 10, 'notnull' => true, 'default' => 'services']);
        $bills->addColumn('supplier_vat_on_debits', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
    }

    public function down(Schema $schema): void
    {
        foreach ([Invoice::TABLE_NAME, RecurringInvoice::TABLE_NAME, CreditNote::TABLE_NAME] as $table) {
            $schema->getTable($table)->dropColumn('vat_on_debits');
        }

        $bills = $schema->getTable(Bill::TABLE_NAME);
        $bills->dropColumn('supply_type');
        $bills->dropColumn('supplier_vat_on_debits');
    }
}
