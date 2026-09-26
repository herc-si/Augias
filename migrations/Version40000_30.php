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
use function sprintf;

/**
 * Lets go of quotes that are no longer there.
 *
 * The link from an invoice to its quote is declared ON DELETE SET NULL, but
 * SQLite enforces no foreign key unless told to on each connection, and
 * nothing told it. Deleting a quote left the invoice pointing at nothing, and
 * the invoice's page could not be opened. The invoice is kept — it has to be
 * — and only the dead link goes. On the other platforms the database already
 * did this, and the statement finds nothing to change.
 */
final class Version40000_30 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear the quote of invoices whose quote was deleted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            'UPDATE %1$s SET quote_id = NULL WHERE quote_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM %2$s q WHERE q.id = %1$s.quote_id)',
            Invoice::TABLE_NAME,
            Quote::TABLE_NAME,
        ));
    }

    public function down(Schema $schema): void
    {
        // The quotes are gone; there is nothing to point back at.
    }
}
