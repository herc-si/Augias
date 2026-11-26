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

final class Version40000_66 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Received e-invoices: what the document is, so a credit note is not filed as a purchase to pay';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(ElectronicInvoiceReceipt::TABLE_NAME)
            ->addColumn('document_type_code', Types::SMALLINT, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(ElectronicInvoiceReceipt::TABLE_NAME)->dropColumn('document_type_code');
    }
}
