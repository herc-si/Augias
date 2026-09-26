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

namespace Augias\CoreBundle\Tests\Doctrine;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Doctrine\ForeignKeys;
use Augias\CoreBundle\Doctrine\Middleware\SqliteForeignKeys;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The schema's ON DELETE rules hold on SQLite too — they did not, and a
 * deleted quote left its invoice pointing at nothing.
 */
#[CoversClass(SqliteForeignKeys::class)]
#[CoversClass(ForeignKeys::class)]
final class ForeignKeysTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testTheDatabaseKeepsTheOnDeleteRules(): void
    {
        $connection = $this->connection();

        if (! $connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The other platforms keep their foreign keys on their own.');
        }

        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));

        $client = ClientFactory::createOne(['company' => $this->company]);
        $quote = QuoteFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => QuoteStatus::Draft]);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Draft, 'quote' => $quote]);

        // Straight to the table, past the ORM: what the database does alone.
        $connection->delete('quotes', ['id' => $quote->getId()], ['id' => UlidType::NAME]);

        self::assertNull($connection->fetchOne('SELECT quote_id FROM invoices WHERE id = ?', [$invoice->getId()], [UlidType::NAME]), 'ON DELETE SET NULL');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM quote_contact WHERE quote_id = ?', [$quote->getId()], [UlidType::NAME]), 'ON DELETE CASCADE');
    }

    /**
     * On a connection of its own: the suite's shared one sits inside a
     * transaction, where SQLite ignores the pragma.
     */
    public function testChangingTheSchemaSuspendsThem(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        ForeignKeys::suspend($connection);
        $off = (int) $connection->fetchOne('PRAGMA foreign_keys');
        ForeignKeys::restore($connection);

        self::assertSame(0, $off);
        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
    }

    /**
     * Why they are suspended: SQLite alters a table by dropping and recreating
     * it, and with the keys on that drop fails outright — or, where it goes
     * through, takes every cascading child row with it.
     */
    public function testAlteringAParentNeedsTheKeysSuspended(): void
    {
        [$connection, $sql] = $this->parentAndChild();

        try {
            foreach ($sql as $statement) {
                $connection->executeStatement($statement);
            }

            self::assertLessThan(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM child'), 'Went through: the children cascaded away.');
        } catch (DriverException) {
            // Refused: the migration would have stopped half-way.
            $this->addToAssertionCount(1);
        }

        [$connection, $sql] = $this->parentAndChild();
        ForeignKeys::suspend($connection);

        foreach ($sql as $statement) {
            $connection->executeStatement($statement);
        }

        ForeignKeys::restore($connection);

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM child'));
        self::assertSame([], $connection->fetchAllAssociative('PRAGMA foreign_key_check'));
    }

    /**
     * @return array{Connection, list<string>} a parent with two children, keys on, and the statements that drop a column from the parent
     */
    private function parentAndChild(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE parent (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parent (id) ON DELETE CASCADE)');
        $connection->executeStatement("INSERT INTO parent (id, name) VALUES (1, 'a'), (2, 'b')");
        $connection->executeStatement('INSERT INTO child (id, parent_id) VALUES (1, 1), (2, 2)');
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        // What SQLitePlatform::getAlterTableSQL() emits to drop a column.
        return [$connection, [
            'CREATE TEMPORARY TABLE __temp__parent AS SELECT id FROM parent',
            'DROP TABLE parent',
            'CREATE TABLE parent (id INTEGER NOT NULL, PRIMARY KEY (id))',
            'INSERT INTO parent (id) SELECT id FROM __temp__parent',
            'DROP TABLE __temp__parent',
        ]];
    }

    public function testMigrationsSuspendThem(): void
    {
        $events = self::getContainer()->get(EntityManagerInterface::class)->getEventManager();

        self::assertTrue($events->hasListeners('onMigrationsMigrating'));
        self::assertTrue($events->hasListeners('onMigrationsMigrated'));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine')->getConnection();
    }
}
