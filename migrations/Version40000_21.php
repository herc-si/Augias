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

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a line say it re-bills money advanced in the client's name.
 *
 * A flag on the line and a total on the document. Both default to nothing, so
 * every invoice already in the database keeps the totals it was issued with —
 * a document that was never about disbursements has none, and nothing is
 * recomputed behind the user's back.
 *
 * The flag lives on `invoice_lines`, which recurring invoices and credit notes
 * share with invoices: an advance made every month is still an advance, and a
 * credit note that takes one back has to be able to say so.
 */
final class Version40000_21 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark a line as a disbursement, and total them on the document';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(Line::TABLE_NAME)
            ->addColumn('disbursement', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

        foreach ([Invoice::TABLE_NAME, RecurringInvoice::TABLE_NAME, CreditNote::TABLE_NAME] as $table) {
            $schema->getTable($table)
                ->addColumn('disbursement_amount', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(Line::TABLE_NAME)->dropColumn('disbursement');

        foreach ([Invoice::TABLE_NAME, RecurringInvoice::TABLE_NAME, CreditNote::TABLE_NAME] as $table) {
            $schema->getTable($table)->dropColumn('disbursement_amount');
        }
    }
}
