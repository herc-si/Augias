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

use Augias\SettingsBundle\Entity\Setting;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stops the default sender being the upstream project's address.
 *
 * Every company was created with `no-reply@solidinvoice.co` as its sender, a
 * domain Augias has nothing to do with. The row is only rewritten where the
 * company never changed it; an address someone chose is left alone. The new
 * default is a placeholder on a reserved domain, which never delivers: the
 * installation has to name its own sender.
 */
final class Version40000_23 extends AbstractMigration
{
    private const string KEY = 'email/from_address';

    private const string UPSTREAM = 'no-reply@solidinvoice.co';

    private const string PLACEHOLDER = 'no-reply@augias.example';

    public function getDescription(): string
    {
        return 'Replace the upstream default sender with a placeholder';
    }

    public function up(Schema $schema): void
    {
        foreach (['setting_value', 'default_value'] as $column) {
            $this->addSql(
                sprintf('UPDATE %s SET %s = :placeholder WHERE setting_key = :key AND %s = :upstream', Setting::TABLE_NAME, $column, $column),
                ['placeholder' => self::PLACEHOLDER, 'key' => self::KEY, 'upstream' => self::UPSTREAM],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['setting_value', 'default_value'] as $column) {
            $this->addSql(
                sprintf('UPDATE %s SET %s = :upstream WHERE setting_key = :key AND %s = :placeholder', Setting::TABLE_NAME, $column, $column),
                ['placeholder' => self::PLACEHOLDER, 'key' => self::KEY, 'upstream' => self::UPSTREAM],
            );
        }
    }
}
