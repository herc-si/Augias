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
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\QuoteBundle\Entity\Quote;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a document is about, in a few words: the "Studio" template made up a
 * title from the client's name instead ("toto mission", 12/10/2026).
 */
final class Version40000_59 extends AbstractMigration
{
    private const array TABLES = [Invoice::TABLE_NAME, RecurringInvoice::TABLE_NAME, CreditNote::TABLE_NAME, Quote::TABLE_NAME];

    public function getDescription(): string
    {
        return 'A subject on invoices, recurring invoices, credit notes and quotes';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->getTable($table)->addColumn('subject', Types::STRING, ['length' => 255, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->getTable($table)->dropColumn('subject');
        }
    }
}
