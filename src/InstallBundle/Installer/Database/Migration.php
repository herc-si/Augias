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

namespace Augias\InstallBundle\Installer\Database;

use Carbon\CarbonImmutable;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\SqlFormatter\SqlFormatter;
use Generator;
use function array_filter;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function sprintf;

final readonly class Migration
{
    private SqlFormatter $sqlFormatter;

    /**
     * @param list<string> $preservedTables tables this instance does not map
     *                                      and must not drop
     */
    public function __construct(
        private DependencyFactory $migrationDependencyFactory,
        private EntityManagerInterface $entityManager,
        private array $preservedTables = [],
    ) {
        $this->sqlFormatter = new SqlFormatter();
    }

    public function isUpToDate(): bool
    {
        $statusCalculator = $this->migrationDependencyFactory->getMigrationStatusCalculator();

        $executedUnavailableMigrations = $statusCalculator->getExecutedUnavailableMigrations();
        $newMigrations = $statusCalculator->getNewMigrations();
        $newMigrationsCount = count($newMigrations);
        $executedUnavailableMigrationsCount = count($executedUnavailableMigrations);

        return $newMigrationsCount === 0 && $executedUnavailableMigrationsCount === 0;
    }

    /**
     * Brings the database up to the current version, by one of two routes.
     *
     * A database that has a migration history knows where it stands, so the
     * migrations it has not run yet are *run*. That is the only way a data
     * migration — a default to seed, a column to backfill, a value to rewrite —
     * ever happens: {@see SchemaTool} compares structure and knows nothing of
     * rows, so recording those migrations as executed without executing them
     * silently drops every change they carry.
     *
     * A database with no history has none that can be replayed: the migrations
     * of this project do not run end to end against an empty database (the
     * oldest of them expect a schema that predates them), and there would be no
     * point, since the schema they add up to is the one the ORM metadata
     * already describes. So it is built in one pass from that metadata and the
     * history is written to match — the usual baseline of a fresh install.
     */
    public function migrate(?callable $callback = null): Generator
    {
        $metadataStorage = $this->migrationDependencyFactory->getMetadataStorage();

        $metadataStorage->ensureInitialized();

        $plan = $this->planToLatestVersion();
        $tracked = count($metadataStorage->getExecutedMigrations()->getItems()) > 0;

        if ($tracked) {
            yield from $this->runPlan($plan, $callback);
        }

        // Still checked on a tracked database: a schema change that shipped
        // without a migration would otherwise never reach it.
        yield from $this->updateSchema($callback);

        if (! $tracked) {
            $this->recordAsExecuted($plan, $metadataStorage);
        }
    }

    private function planToLatestVersion(): MigrationPlanList
    {
        $version = $this->migrationDependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');

        return $this->migrationDependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($version);
    }

    private function runPlan(MigrationPlanList $plan, ?callable $callback): Generator
    {
        if (0 === count($plan)) {
            return;
        }

        $executed = $this->migrationDependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());

        if (null === $callback) {
            return;
        }

        foreach ($executed as $version => $queries) {
            yield from $callback(sprintf('-- %s', $version));

            foreach ($queries as $query) {
                yield from $callback($this->sqlFormatter->format($query->getStatement()));
            }
        }
    }

    private function updateSchema(?callable $callback): Generator
    {
        $tables = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool = new SchemaTool($this->entityManager);
        $conn = $this->entityManager->getConnection();

        // ORM 3's SchemaTool::getUpdateSchemaSql() no longer has a "save mode" (the
        // boolean second argument was removed in ORM 3), so it now emits DROP TABLE
        // statements for any table present in the database but absent from the ORM
        // metadata. The migrations metadata table (created by ensureInitialized()
        // above) is exactly such a table, so without excluding it the generated SQL
        // would try to drop it. Filter it out of the schema introspection while the
        // update SQL is computed.
        $dbalConfiguration = $conn->getConfiguration();
        $previousFilter = $dbalConfiguration->getSchemaAssetsFilter();
        $migrationsTable = $this->migrationsTableName();

        // The same exclusion, for the same reason, applied to tables another
        // application owns.
        //
        // A database can be shared — a deployment that runs Augias beside
        // something of its own, a bundle installed on one instance and not the
        // other. Every table that instance does not map is a table this update
        // would drop, and it would drop it quietly, on an ordinary web
        // request, because `UpgradeListener` runs this on one. `AUGIAS_PRESERVED_TABLES`
        // names them. It is deliberately a list of names and not a pattern:
        // a pattern is how a list of things not to destroy grows by accident.
        // `csv:` on an empty environment variable yields [null], not [], so the
        // list is normalised rather than trusted — a null in there would be a
        // value compared against every table name for no reason.
        $preserved = array_values(array_filter($this->preservedTables, is_string(...)));

        $dbalConfiguration->setSchemaAssetsFilter(
            static function (string $assetName) use ($previousFilter, $migrationsTable, $preserved): bool {
                if ($migrationsTable !== null && $assetName === $migrationsTable) {
                    return false;
                }

                if (in_array($assetName, $preserved, true)) {
                    return false;
                }

                return $previousFilter($assetName);
            }
        );

        try {
            $updateSchemaSql = $schemaTool->getUpdateSchemaSql($tables);
        } finally {
            $dbalConfiguration->setSchemaAssetsFilter($previousFilter);
        }

        if ($updateSchemaSql !== []) {
            foreach ($updateSchemaSql as $sql) {
                $conn->executeStatement($sql);

                if (null !== $callback) {
                    yield from $callback($this->sqlFormatter->format($sql));
                }
            }
        } elseif (null !== $callback) {
            yield from $callback('Database schema is already up to date.');
        }
    }

    private function migrationsTableName(): ?string
    {
        $storageConfiguration = $this->migrationDependencyFactory->getConfiguration()->getMetadataStorageConfiguration();

        return $storageConfiguration instanceof TableMetadataStorageConfiguration
            ? $storageConfiguration->getTableName()
            : null;
    }

    private function recordAsExecuted(MigrationPlanList $plan, MetadataStorage $metadataStorage): void
    {
        $now = CarbonImmutable::now();

        foreach ($plan->getItems() as $item) {
            $metadataStorage->complete(new ExecutionResult($item->getVersion(), $item->getDirection(), $now));
        }
    }
}
