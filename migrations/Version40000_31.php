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

use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\UserInvitation;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use function sprintf;

/**
 * Gives every member of a company a role.
 *
 * Until now everyone in a company could do everything, closing it included.
 * Nobody loses anything: every member becomes an administrator, which is
 * what they were in all but name, and each company's earliest member becomes
 * its owner — the company did not record who created it, and the first
 * account in is the one that did.
 *
 * Pending invitations promised the same full access, so they keep it.
 */
final class Version40000_31 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give company members and pending invitations a role';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(Membership::TABLE_NAME)->addColumn('role', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'admin']);
        $schema->getTable(UserInvitation::TABLE_NAME)->addColumn('role', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'billing']);
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(sprintf('UPDATE %s SET role = ?', UserInvitation::TABLE_NAME), ['admin']);

        // Ids go back exactly as they came out, bound as plain strings. Bound
        // as binary, PDO SQLite matches none of them.
        $companies = $this->connection->fetchFirstColumn(sprintf('SELECT DISTINCT company_id FROM %s', Membership::TABLE_NAME));

        foreach ($companies as $companyId) {
            $first = $this->connection->fetchOne(
                sprintf('SELECT m.user_id FROM %s m INNER JOIN users u ON u.id = m.user_id WHERE m.company_id = ? ORDER BY u.created ASC, u.id ASC', Membership::TABLE_NAME),
                [$companyId],
            );

            if (false === $first) {
                continue;
            }

            $this->connection->update(
                Membership::TABLE_NAME,
                ['role' => 'owner'],
                ['company_id' => $companyId, 'user_id' => $first],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(Membership::TABLE_NAME)->dropColumn('role');
        $schema->getTable(UserInvitation::TABLE_NAME)->dropColumn('role');
    }
}
