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

use Augias\SettingsBundle\SystemConfig;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the electronic invoicing switch from the company settings to the
 * invoice ones: the settings page groups settings by the first segment of
 * their key, and the switch is about what an invoice carries and where it
 * goes. The value each company chose is kept.
 */
final class Version40000_39 extends AbstractMigration
{
    private const string OLD_KEY = 'system/company/electronic_invoicing_enabled';

    public function getDescription(): string
    {
        return 'Move the electronic invoicing setting to the invoice settings';
    }

    public function up(Schema $schema): void
    {
        // Data only — see postUp().
    }

    public function down(Schema $schema): void
    {
        // Data only — see postDown().
    }

    /**
     * @throws Exception
     */
    public function postUp(Schema $schema): void
    {
        $this->connection->update('app_config', ['setting_key' => SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH], ['setting_key' => self::OLD_KEY]);
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        $this->connection->update('app_config', ['setting_key' => self::OLD_KEY], ['setting_key' => SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH]);
    }
}
