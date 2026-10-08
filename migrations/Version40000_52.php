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

use Augias\CoreBundle\Entity\DocumentActivity;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Beside a client's acceptance, the quote as it stood when they accepted it:
 * the PDF kept in the attachment store, and its SHA-256, which shows it has
 * not changed since.
 */
final class Version40000_52 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the quote a client accepted, and its fingerprint';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(DocumentActivity::TABLE_NAME);
        $table->addColumn('proof_path', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('proof_sha256', Types::STRING, ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(DocumentActivity::TABLE_NAME);
        $table->dropColumn('proof_path');
        $table->dropColumn('proof_sha256');
    }
}
