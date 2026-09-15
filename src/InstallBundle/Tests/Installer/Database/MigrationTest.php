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

use Augias\InstallBundle\Installer\Database\Migration;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Carbon\CarbonImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function count;
use function iterator_to_array;
use function sprintf;

/**
 * A database that already carries data is upgraded by running the migrations
 * that have not run yet — not by recording them as though they had.
 */
#[CoversClass(Migration::class)]
final class MigrationTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    /**
     * Seeds the credit note numbering settings for companies that already
     * exist, and does all of it in postUp(): a migration whose whole purpose is
     * data, so skipping it leaves no trace in the schema to notice later.
     */
    private const string PENDING_MIGRATION = 'DoctrineMigrations\Version40000_13';

    private const array SEEDED_SETTINGS = [
        'credit_note/id_generation/strategy',
        'credit_note/id_generation/id_prefix',
        'credit_note/id_generation/id_suffix',
    ];

    public function testAPendingDataMigrationRunsOnATrackedDatabase(): void
    {
        $container = self::getContainer();

        $config = $container->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);

        $dependencyFactory = $container->get(DependencyFactory::class);
        self::assertInstanceOf(DependencyFactory::class, $dependencyFactory);

        $migration = $container->get(Migration::class);
        self::assertInstanceOf(Migration::class, $migration);

        // The company predates the setting, the state the migration exists for.
        foreach (self::SEEDED_SETTINGS as $key) {
            $config->remove($key);
            self::assertNull($config->get($key, $this->company));
        }

        $this->recordEveryMigrationExceptThePendingOne($dependencyFactory);

        iterator_to_array($migration->migrate());

        foreach (self::SEEDED_SETTINGS as $key) {
            self::assertNotNull($config->get($key, $this->company), sprintf('The migration did not seed "%s".', $key));
        }
    }

    /**
     * The other half of the same rule: history that was never recorded cannot
     * be replayed. Running it would mean applying every migration of the
     * project to a schema that already has them, so an untracked database is
     * baselined instead — and nothing that a migration carries is applied to it.
     */
    public function testAnUntrackedDatabaseIsBaselinedRatherThanReplayed(): void
    {
        $container = self::getContainer();

        $config = $container->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);

        $dependencyFactory = $container->get(DependencyFactory::class);
        self::assertInstanceOf(DependencyFactory::class, $dependencyFactory);

        $migration = $container->get(Migration::class);
        self::assertInstanceOf(Migration::class, $migration);

        foreach (self::SEEDED_SETTINGS as $key) {
            $config->remove($key);
        }

        iterator_to_array($migration->migrate());

        $available = $dependencyFactory->getMigrationRepository()->getMigrations()->getItems();
        $executed = $dependencyFactory->getMetadataStorage()->getExecutedMigrations()->getItems();

        self::assertCount(count($available), $executed);
        self::assertTrue($migration->isUpToDate());

        foreach (self::SEEDED_SETTINGS as $key) {
            self::assertNull($config->get($key, $this->company), sprintf('"%s" was seeded by a migration that never ran here.', $key));
        }
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get('doctrine')->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        // On a platform where DDL commits the surrounding transaction, the table
        // created below outlives the test's rollback.
        $schemaManager = $connection->createSchemaManager();

        if ($schemaManager->tablesExist(['migration_versions'])) {
            $schemaManager->dropTable('migration_versions');
        }

        parent::tearDown();
    }

    /**
     * Leaves exactly one migration to run, so that the assertion is about that
     * migration rather than about the whole history replaying.
     */
    private function recordEveryMigrationExceptThePendingOne(DependencyFactory $dependencyFactory): void
    {
        $metadataStorage = $dependencyFactory->getMetadataStorage();
        $metadataStorage->ensureInitialized();

        $now = CarbonImmutable::now();

        foreach ($dependencyFactory->getMigrationRepository()->getMigrations()->getItems() as $availableMigration) {
            if (self::PENDING_MIGRATION === (string) $availableMigration->getVersion()) {
                continue;
            }

            $metadataStorage->complete(new ExecutionResult($availableMigration->getVersion(), Direction::UP, $now));
        }
    }
}
