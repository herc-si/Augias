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

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the annual VAT cycle from companies that chose it.
 *
 * The setting only ever had one lawful use: the régime réel simplifié filed a
 * single CA12 a year while URSSAF still wanted turnover every quarter. Article
 * 38 of the loi de finances pour 2025 abolishes that regime on 1 January 2027
 * and the CA12 with it, so nothing declares VAT annually any more and the
 * choice has been removed from the form.
 *
 * A stored 'year' left behind would be a value the form can no longer display:
 * the settings screen would fall back to showing nothing selected while the
 * books went on grouping VAT annually, which is the worst of both. Resetting to
 * empty puts VAT back on the rhythm of the books — the default, and what every
 * company that never touched this setting already does.
 *
 * Existing periods and declarations are left alone. A year already declared was
 * declared lawfully under the rules of its time, and rewriting history to match
 * today's form would be a lie about what was filed.
 */
final class Version40000_16 extends AbstractMigration
{
    private const string SETTING = 'accounting/vat_periodicity';

    public function getDescription(): string
    {
        return 'Reset the annual VAT cycle to follow the books, the CA12 having been abolished';
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
        $this->connection->update(
            'app_config',
            ['setting_value' => ''],
            ['setting_key' => self::SETTING, 'setting_value' => 'year'],
        );
    }

    /**
     * Irreversible on purpose: an empty value is indistinguishable from one a
     * company set itself, so restoring 'year' would hand the annual cycle to
     * companies that never asked for it.
     */
    public function postDown(Schema $schema): void
    {
    }
}
