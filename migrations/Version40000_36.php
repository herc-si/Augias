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

use Augias\AccountingBundle\Entity\BankAccount;
use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\BillBundle\Entity\BillPayment;
use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Entity\Company;
use Augias\PaymentBundle\Entity\Payment;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bank accounts and the lines of the statements imported into them by hand.
 *
 * A line points at the payment it proves — a client's, or a supplier's —
 * and lets go of it if the payment is deleted: the statement line stays true
 * whatever happens to the record it was matched with.
 */
final class Version40000_36 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import bank statements and reconcile their lines';
    }

    public function up(Schema $schema): void
    {
        $accounts = $schema->createTable(BankAccount::TABLE_NAME);
        $accounts->addColumn('id', 'ulid', ['notnull' => true]);
        $accounts->addColumn('company_id', 'ulid', ['notnull' => true]);
        $accounts->addColumn('name', Types::STRING, ['length' => 100, 'notnull' => true]);
        $accounts->addColumn('iban', Types::STRING, ['length' => 34, 'notnull' => false]);
        $accounts->addColumn('currency_code', Types::STRING, ['length' => 3, 'notnull' => true]);
        $accounts->addColumn('created', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $accounts->addColumn('updated', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $accounts->setPrimaryKey(['id']);
        $accounts->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);

        $lines = $schema->createTable(BankTransaction::TABLE_NAME);
        $lines->addColumn('id', 'ulid', ['notnull' => true]);
        $lines->addColumn('company_id', 'ulid', ['notnull' => true]);
        $lines->addColumn('bank_account_id', 'ulid', ['notnull' => true]);
        $lines->addColumn('booking_date', Types::DATE_IMMUTABLE, ['notnull' => true]);
        $lines->addColumn('amount', BigIntegerType::NAME, ['notnull' => true]);
        $lines->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
        $lines->addColumn('counterparty_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $lines->addColumn('reference', Types::STRING, ['length' => 255, 'notnull' => false]);
        $lines->addColumn('fingerprint', Types::STRING, ['length' => 64, 'notnull' => true]);
        $lines->addColumn('status', Types::STRING, ['length' => 20, 'notnull' => true]);
        $lines->addColumn('payment_id', 'ulid', ['notnull' => false]);
        $lines->addColumn('bill_payment_id', 'ulid', ['notnull' => false]);
        $lines->addColumn('created', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $lines->addColumn('updated', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $lines->setPrimaryKey(['id']);
        $lines->addIndex(['bank_account_id', 'status', 'booking_date'], 'idx_bank_transaction_status');
        $lines->addUniqueIndex(['bank_account_id', 'fingerprint'], 'unique_bank_transaction_fingerprint');
        $lines->addForeignKeyConstraint(Company::TABLE_NAME, ['company_id'], ['id'], ['onDelete' => 'CASCADE']);
        $lines->addForeignKeyConstraint(BankAccount::TABLE_NAME, ['bank_account_id'], ['id'], ['onDelete' => 'CASCADE']);
        $lines->addForeignKeyConstraint(Payment::TABLE_NAME, ['payment_id'], ['id'], ['onDelete' => 'SET NULL']);
        $lines->addForeignKeyConstraint(BillPayment::TABLE_NAME, ['bill_payment_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(BankTransaction::TABLE_NAME);
        $schema->dropTable(BankAccount::TABLE_NAME);
    }
}
