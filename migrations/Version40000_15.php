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
 * Turns the stored help text of a setting into a translation key.
 *
 * A setting's description is written into app_config when a company is created
 * and read back straight into the form's help text, so whatever language it was
 * written in is the language every reader gets. The settings screen was English
 * on a French instance for that reason alone.
 *
 * AccountingBundle already stored keys rather than prose; this brings the rest
 * in line with it. Rows are matched on setting_key, not on the text, because
 * the text stored depends on which version of the provider created the company.
 */
final class Version40000_15 extends AbstractMigration
{
    private const array KEYS = [
        'invoice/watermark' => 'invoice.settings.watermark.description',
        'invoice/bcc_address' => 'invoice.settings.bcc_address.description',
        'invoice/email_subject' => 'invoice.settings.email_subject.description',
        'invoice/id_generation/id_prefix' => 'invoice.settings.id_generation.id_prefix.description',
        'invoice/id_generation/id_suffix' => 'invoice.settings.id_generation.id_suffix.description',
        'invoice/reminder/enabled' => 'invoice.settings.reminder.enabled.description',
        'invoice/reminder/pre_due_enabled' => 'invoice.settings.reminder.pre_due_enabled.description',
        'invoice/reminder/pre_due_days' => 'invoice.settings.reminder.pre_due_days.description',
        'credit_note/id_generation/id_prefix' => 'credit_note.settings.id_generation.id_prefix.description',
        'credit_note/id_generation/id_suffix' => 'credit_note.settings.id_generation.id_suffix.description',
        'quote/watermark' => 'quote.settings.watermark.description',
        'quote/bcc_address' => 'quote.settings.bcc_address.description',
        'quote/email_subject' => 'quote.settings.email_subject.description',
        'quote/id_generation/id_prefix' => 'quote.settings.id_generation.id_prefix.description',
        'quote/id_generation/id_suffix' => 'quote.settings.id_generation.id_suffix.description',
        'system/general/hide_powered_by' => 'saas.settings.hide_powered_by.description',
        'system/domain/custom_domain' => 'saas.settings.custom_domain.description',
        'design/template' => 'saas.settings.billing_template.description',
    ];

    public function getDescription(): string
    {
        return 'Store settings help as translation keys rather than English prose';
    }

    public function up(Schema $schema): void
    {
        // Data only — see postUp().
    }

    public function down(Schema $schema): void
    {
        // Data only. The prose is not restored: it is in the catalogues now, and
        // reading it back out of them to write it into rows would be the defect
        // this undoes.
    }

    /**
     * @throws Exception
     */
    public function postUp(Schema $schema): void
    {
        foreach (self::KEYS as $setting => $key) {
            $this->connection->update(
                'app_config',
                ['description' => $key],
                ['setting_key' => $setting],
            );
        }
    }
}
