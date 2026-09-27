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

use Augias\CoreBundle\Config\DesignConfigProvider;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Uid\Ulid;
use function json_encode;

/**
 * Seeds the self-hosted "Hide Powered by Augias" box for companies that
 * already exist: SettingsRepository::store() only ever updates a row. The
 * hosted service keeps its own paid switch (system/general/hide_powered_by).
 */
final class Version40000_40 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the design/hide_powered_by setting';
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
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM companies') as $company) {
            $exists = $this->connection->fetchOne('SELECT 1 FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, DesignConfigProvider::HIDE_POWERED_BY]);

            if (false !== $exists) {
                continue;
            }

            $this->connection->insert('app_config', [
                'id' => new Ulid()->toBinary(),
                'company_id' => $company,
                'setting_key' => DesignConfigProvider::HIDE_POWERED_BY,
                'setting_value' => '0',
                'description' => 'settings.page.design.hide_powered_by.description',
                'field_type' => CheckboxType::class,
                'form_options' => json_encode(['label' => 'settings.page.design.hide_powered_by.label']),
                'default_value' => '0',
            ]);
        }
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        $this->connection->delete('app_config', ['setting_key' => DesignConfigProvider::HIDE_POWERED_BY]);
    }
}
