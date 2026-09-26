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

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use function sprintf;
use function strtoupper;

/**
 * Does, once, what SQLite's foreign keys would have done all along.
 *
 * The schema declares ON DELETE CASCADE and SET NULL, but SQLite enforced no
 * foreign key until each connection asked, which none did until now. Rows
 * whose parent was deleted in the meantime are still there: the links of a
 * deleted quote to its contacts, for one. A row declared CASCADE goes, a
 * column declared SET NULL is emptied, and anything else is left for a human.
 *
 * Only SQLite: the other platforms kept their keys and have nothing to find.
 */
final class Version40000_35 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Apply the ON DELETE rules SQLite skipped while its foreign keys were off';
    }

    public function up(Schema $schema): void
    {
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $keys = [];

        foreach ($this->connection->fetchAllAssociative('PRAGMA foreign_key_check') as $violation) {
            $table = (string) $violation['table'];
            $keys[$table] ??= $this->foreignKeys($table);
            $key = $keys[$table][(int) $violation['fkid']] ?? null;

            if (null === $key || null === $violation['rowid']) {
                continue;
            }

            $sql = match ($key['on_delete']) {
                'CASCADE' => sprintf('DELETE FROM "%s" WHERE rowid = ?', $table),
                'SET NULL' => sprintf('UPDATE "%s" SET "%s" = NULL WHERE rowid = ?', $table, $key['from']),
                default => null,
            };

            if (null !== $sql) {
                $this->addSql($sql, [(int) $violation['rowid']]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // The parents are gone; there is nothing to point back at.
    }

    /**
     * @return array<int, array{from: string, on_delete: string}>
     */
    private function foreignKeys(string $table): array
    {
        $keys = [];

        foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA foreign_key_list("%s")', $table)) as $row) {
            $keys[(int) $row['id']] = ['from' => (string) $row['from'], 'on_delete' => strtoupper((string) $row['on_delete'])];
        }

        return $keys;
    }
}
