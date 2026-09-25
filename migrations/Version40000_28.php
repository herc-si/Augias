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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keeps what a received e-invoice says about its VAT — the amount, goods or
 * services, the supplier's option for debits — so the bill made from it does
 * not have to be told again. Empty on everything already received.
 */
final class Version40000_28 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the VAT details of received e-invoices';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceReceipt::TABLE_NAME);
        $table->addColumn('tax_amount', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('supply_type', Types::STRING, ['length' => 10, 'notnull' => false]);
        $table->addColumn('supplier_vat_on_debits', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceReceipt::TABLE_NAME);
        $table->dropColumn('tax_amount');
        $table->dropColumn('supply_type');
        $table->dropColumn('supplier_vat_on_debits');
    }
}
