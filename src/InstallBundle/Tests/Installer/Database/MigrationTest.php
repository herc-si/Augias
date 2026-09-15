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

namespace Augias\InstallBundle\Tests\Installer\Database;

use Augias\CoreBundle\Doctrine\Migrations\NaturalVersionComparator;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Installer\Database\Migration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Comparator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Ulid;
use function count;
use function iterator_to_array;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Both routes through {@see Migration::migrate()} run against a database of
 * their own — a throwaway SQLite file, with its own connection.
 *
 * Not out of tidiness: creating the migrations table is DDL, and on a platform
 * where DDL commits the surrounding transaction it would end the one that keeps
 * the rest of the suite isolated, taking every test that follows down with it.
 *
 * A database of its own turned out not to be enough. With this class in the
 * default run, about one run in five failed somewhere else entirely — ledger
 * lock dates not enforced, a deleted company's security token still present —
 * in whichever tests the random order happened to put afterwards. Bisecting the
 * merges found this class; running the suite without it was green eight times
 * out of eight. What exactly escapes is not identified: it is not the shared ORM
 * configuration (cloning it changed nothing), and it survives a second entity
 * manager and a connection of its own.
 *
 * So it joins `installation` and `saas-kernel` in the groups excluded by
 * default and run in an invocation of their own — the same answer this project
 * already gives to a test that cannot share a process with the rest. Process
 * isolation per test is not an option here: tests/bootstrap.php clears
 * var/cache/test and rebuilds the schema in every process, so a child spawned
 * mid-run destroys the database the parent is still using.
 */
#[CoversClass(Migration::class)]
#[Group('migrations')]
final class MigrationTest extends KernelTestCase
{
    /**
     * Seeds the credit note numbering settings for companies that already
     * exist, and does all of it in postUp(): a migration whose whole purpose is
     * data, so skipping it leaves no trace in the schema to notice later.
     */
    private const string DATA_MIGRATION = 'DoctrineMigrations\Version40000_13';

    private const array SEEDED_SETTINGS = [
        'credit_note/id_generation/strategy',
        'credit_note/id_generation/id_prefix',
        'credit_note/id_generation/id_suffix',
    ];

    private string $databaseFile;

    private Connection $connection;

    private Migration $migration;

    private DependencyFactory $dependencyFactory;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $this->databaseFile = sprintf('%s/%s.db', sys_get_temp_dir(), uniqid('augias-migration-', true));

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->databaseFile,
        ]);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        // The mapping of the application, pointed at a database of its own.
        $isolated = new EntityManager($this->connection, $entityManager->getConfiguration());

        $this->dependencyFactory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => self::getContainer()->getParameter('kernel.project_dir') . '/migrations'],
                'table_storage' => ['table_name' => 'migration_versions'],
            ]),
            new ExistingConnection($this->connection),
        );

        // As configured in config/packages/doctrine_migrations.php: without it
        // Version40000_9 runs after Version40000_10.
        $this->dependencyFactory->setDefinition(Comparator::class, static fn (): Comparator => new NaturalVersionComparator());

        $this->migration = new Migration($this->dependencyFactory, $isolated);
    }

    protected function tearDown(): void
    {
        $this->connection->close();

        new Filesystem()->remove($this->databaseFile);

        parent::tearDown();
    }

    /**
     * An empty database has no history that can be replayed — the oldest
     * migrations of this project expect a schema that predates them — so the
     * schema is built from the mapping in one pass and the history written to
     * match. Nothing a migration carries is applied.
     */
    public function testAnEmptyDatabaseIsBaselinedRatherThanReplayed(): void
    {
        iterator_to_array($this->migration->migrate());

        $schemaManager = $this->connection->createSchemaManager();
        self::assertTrue($schemaManager->tablesExist([Company::TABLE_NAME]));

        $available = $this->dependencyFactory->getMigrationRepository()->getMigrations()->getItems();
        $executed = $this->dependencyFactory->getMetadataStorage()->getExecutedMigrations()->getItems();

        self::assertNotEmpty($available);
        self::assertCount(count($available), $executed);
        self::assertTrue($this->migration->isUpToDate());
    }

    /**
     * A database that knows where it stands runs what it has not run yet — the
     * only way a migration that carries data rather than structure ever
     * happens.
     */
    public function testAPendingDataMigrationRunsOnATrackedDatabase(): void
    {
        iterator_to_array($this->migration->migrate());

        $company = new Ulid();
        $this->connection->insert(Company::TABLE_NAME, ['id' => $company->toBinary(), 'name' => 'Baker Street Bakery']);

        // The company predates the setting, the state the migration exists for.
        $this->connection->delete('migration_versions', ['version' => self::DATA_MIGRATION]);
        self::assertSame(0, $this->countSeededSettings($company));

        iterator_to_array($this->migration->migrate());

        self::assertSame(count(self::SEEDED_SETTINGS), $this->countSeededSettings($company));
    }

    private function countSeededSettings(Ulid $company): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM app_config WHERE company_id = ? AND setting_key IN (?, ?, ?)',
            [$company->toBinary(), ...self::SEEDED_SETTINGS],
        );
    }
}
