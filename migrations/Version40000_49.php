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
 * The subject of a quote's or an invoice's email was seeded in English for
 * every company: "New Quotation - #{id}". Where a company kept it as it was,
 * it is emptied, and the subject then follows the language of the app
 * ("Devis DEV-1-2026 – Acme"). A subject a company wrote itself is left alone.
 */
final class Version40000_49 extends AbstractMigration
{
    private const array ENGLISH_DEFAULTS = [
        'quote/email_subject' => 'New Quotation - #{id}',
        'invoice/email_subject' => 'New Invoice - #{id}',
    ];

    public function getDescription(): string
    {
        return 'Let the subject of quote and invoice emails follow the language of the app';
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
        foreach (self::ENGLISH_DEFAULTS as $key => $english) {
            $this->connection->executeStatement(
                'UPDATE app_config SET setting_value = NULL WHERE setting_key = ? AND setting_value = ?',
                [$key, $english],
            );
            $this->connection->executeStatement(
                'UPDATE app_config SET default_value = NULL WHERE setting_key = ? AND default_value = ?',
                [$key, $english],
            );
        }
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        foreach (self::ENGLISH_DEFAULTS as $key => $english) {
            $this->connection->executeStatement(
                'UPDATE app_config SET setting_value = ? WHERE setting_key = ? AND setting_value IS NULL',
                [$english, $key],
            );
            $this->connection->executeStatement(
                'UPDATE app_config SET default_value = ? WHERE setting_key = ? AND default_value IS NULL',
                [$english, $key],
            );
        }
    }
}
