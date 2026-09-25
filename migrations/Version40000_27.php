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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remembers what the e-invoicing platform last said about the account in use.
 *
 * Empty for every setting already there, which keeps it working as it did:
 * the hourly check fills it in, and only then does an account the platform
 * has not verified stop being used.
 */
final class Version40000_27 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record whether the e-invoicing platform has verified the account';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceProviderSetting::TABLE_NAME);
        $table->addColumn('account_verification', Types::STRING, ['length' => 20, 'notnull' => false]);
        $table->addColumn('account_checked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(ElectronicInvoiceProviderSetting::TABLE_NAME);
        $table->dropColumn('account_verification');
        $table->dropColumn('account_checked_at');
    }
}
