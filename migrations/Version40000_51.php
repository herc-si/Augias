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
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use function sprintf;

/**
 * Invoices and credit notes take their number when they are finalised, not
 * when they are drafted: a draft that took one and was dropped left a gap in
 * the series (08/10/2026). A draft has no number, stored as NULL, and a number
 * is unique within its company — a unique index lets any number of NULLs
 * through.
 *
 * Drafts that already have a number keep it: renumbering documents behind
 * their author's back would be worse than the gap.
 *
 * A company whose series already holds the same number twice — typed by hand,
 * which the form allowed — does not get the index: the migration says so
 * rather than failing the upgrade, and the numbers are left to sort out.
 */
final class Version40000_51 extends AbstractMigration
{
    /**
     * @var array<string, array{string, string}>
     */
    private const array SERIES = [
        Invoice::TABLE_NAME => ['invoice_id', Invoice::NUMBER_INDEX],
        CreditNote::TABLE_NAME => ['credit_note_id', CreditNote::NUMBER_INDEX],
    ];

    public function getDescription(): string
    {
        return 'Number invoices and credit notes when finalised: no number on a draft, one number per company';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SERIES as $tableName => [$column]) {
            $schema->getTable($tableName)->modifyColumn($column, ['notnull' => false]);
        }
    }

    /**
     * In this order: the empty numbers of drafts become NULL before the index
     * that two empty strings in one company would break.
     *
     * @throws Exception
     */
    public function postUp(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        foreach (self::SERIES as $tableName => [$column, $index]) {
            $this->connection->executeStatement(sprintf("UPDATE %s SET %s = NULL WHERE %s = ''", $tableName, $column, $column));

            $duplicates = (int) $this->connection->fetchOne(sprintf(
                'SELECT COUNT(*) FROM (SELECT company_id, %1$s FROM %2$s WHERE %1$s IS NOT NULL GROUP BY company_id, %1$s HAVING COUNT(*) > 1) d',
                $column,
                $tableName,
            ));

            if ($duplicates > 0) {
                $this->write(sprintf('<comment>%d number(s) used twice in %s: unique index %s on (company_id, %s) not created. Create it once they are fixed.</comment>', $duplicates, $tableName, $index, $column));

                continue;
            }

            $schemaManager->createIndex(new Index($index, ['company_id', $column], true), $tableName);
        }
    }

    /**
     * @throws Exception
     */
    public function preDown(Schema $schema): void
    {
        foreach (self::SERIES as $tableName => [$column]) {
            $this->connection->executeStatement(sprintf("UPDATE %s SET %s = '' WHERE %s IS NULL", $tableName, $column, $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::SERIES as $tableName => [$column, $index]) {
            $table = $schema->getTable($tableName);

            if ($table->hasIndex($index)) {
                $table->dropIndex($index);
            }

            $table->modifyColumn($column, ['notnull' => true]);
        }
    }
}
