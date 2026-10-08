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

use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Form\Type\BicType;
use Augias\CoreBundle\Form\Type\IbanType;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Uid\Ulid;
use function json_encode;

/**
 * The bank details move from the design tab to the company's own settings,
 * with the bank's name beside them: what a company already entered is kept,
 * under its new key and its checked field type.
 *
 * SettingsRepository::store() only ever updates a row, so every company gets
 * one row per key — moved, or seeded empty.
 */
final class Version40000_53 extends AbstractMigration
{
    /** @var array<string, string> new key => key under the design tab */
    private const array MOVED = [
        CompanyBankDetails::IBAN => 'design/iban',
        CompanyBankDetails::BIC => 'design/bic',
    ];

    public function getDescription(): string
    {
        return 'Move the bank details to the company settings, and add the bank name';
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
        $types = [
            CompanyBankDetails::BANK_NAME => [TextType::class, ['attr' => ['maxlength' => 100]]],
            CompanyBankDetails::IBAN => [IbanType::class, []],
            CompanyBankDetails::BIC => [BicType::class, []],
        ];

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM companies') as $company) {
            foreach ($types as $key => [$type, $options]) {
                if (false !== $this->connection->fetchOne('SELECT 1 FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, $key])) {
                    continue;
                }

                $fields = ['setting_key' => $key, 'description' => null, 'field_type' => $type, 'form_options' => json_encode($options)];
                $old = self::MOVED[$key] ?? null;

                if (null !== $old && false !== $this->connection->fetchOne('SELECT 1 FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, $old])) {
                    $this->connection->update('app_config', $fields, ['company_id' => $company, 'setting_key' => $old]);

                    continue;
                }

                // Through UlidType: binary on MySQL and SQLite, a uuid on PostgreSQL,
                // which refuses the binary form.
                $this->connection->insert('app_config', $fields + [
                    'id' => new Ulid(),
                    'company_id' => $company,
                    'setting_value' => null,
                    'default_value' => null,
                ], ['id' => UlidType::NAME]);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        $this->connection->delete('app_config', ['setting_key' => CompanyBankDetails::BANK_NAME]);

        $this->connection->update('app_config', ['setting_key' => 'design/iban', 'description' => 'settings.page.design.iban.description', 'field_type' => TextType::class, 'form_options' => json_encode(['label' => 'settings.page.design.iban.label', 'attr' => ['placeholder' => 'FR76 3000 6000 0112 3456 7890 189', 'maxlength' => 42]])], ['setting_key' => CompanyBankDetails::IBAN]);
        $this->connection->update('app_config', ['setting_key' => 'design/bic', 'description' => 'settings.page.design.bic.description', 'field_type' => TextType::class, 'form_options' => json_encode(['label' => 'settings.page.design.bic.label', 'attr' => ['maxlength' => 11]])], ['setting_key' => CompanyBankDetails::BIC]);
    }
}
