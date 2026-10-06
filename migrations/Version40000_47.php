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

final class Version40000_47 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep why a received invoice was refused or disputed, and when';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceReceipt::TABLE_NAME);
        $table->addColumn('response_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('response_comment', Types::TEXT, ['notnull' => false]);
        $table->addColumn('responded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceReceipt::TABLE_NAME);
        $table->dropColumn('response_reason');
        $table->dropColumn('response_comment');
        $table->dropColumn('responded_at');
    }
}
