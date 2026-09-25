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
 * Lets a line say whether it sells goods or a service.
 *
 * Every line already in the database becomes a service, which is what the
 * application has always treated it as: the VAT was booked on payment, and
 * the e-invoice declared services. Nothing issued changes meaning.
 *
 * `invoice_lines` is shared by invoices, recurring invoices and credit notes,
 * so one column covers all three; quotes keep their own table.
 */
final class Version40000_24 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark a line as goods or services';
    }

    public function up(Schema $schema): void
    {
        foreach ([InvoiceLine::TABLE_NAME, QuoteLine::TABLE_NAME] as $table) {
            $schema->getTable($table)
                ->addColumn('supply_type', Types::STRING, ['length' => 10, 'notnull' => true, 'default' => 'services']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([InvoiceLine::TABLE_NAME, QuoteLine::TABLE_NAME] as $table) {
            $schema->getTable($table)->dropColumn('supply_type');
        }
    }
}
