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

use Augias\CoreBundle\Form\Type\BillingIdConfigurationType;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Uid\Ulid;
use function json_encode;

/**
 * Seeds the credit note numbering settings for companies that already exist.
 *
 * {@see \Augias\CoreBundle\Company\DefaultData} writes a config provider's
 * settings when a company is created, and never again, so a setting added to a
 * provider afterwards reaches new companies only.
 *
 * The strategy is fixed to auto_increment and the setting offers no other
 * choice: a credit note has to be numbered in an unbroken run.
 */
final class Version40000_13 extends AbstractMigration
{
    private const string STRATEGY = 'credit_note/id_generation/strategy';

    private const string PREFIX = 'credit_note/id_generation/id_prefix';

    private const string SUFFIX = 'credit_note/id_generation/id_suffix';

    public function getDescription(): string
    {
        return 'Seed the credit note numbering settings for existing companies';
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
        $settings = [
            [self::STRATEGY, 'auto_increment', '', BillingIdConfigurationType::class, ['sequential_only' => true]],
            [self::PREFIX, 'AV-', 'Printed before the number. Example: AV-', TextType::class, []],
            [self::SUFFIX, '-{year}', 'Printed after the number. Use {year} for the current year, as in -{year}', TextType::class, []],
        ];

        foreach ($this->connection->fetchAllAssociative('SELECT id FROM companies') as $company) {
            foreach ($settings as [$key, $value, $description, $fieldType, $formOptions]) {
                $exists = $this->connection->fetchOne(
                    'SELECT 1 FROM app_config WHERE company_id = ? AND setting_key = ?',
                    [$company['id'], $key],
                );

                if (false !== $exists) {
                    continue;
                }

                $this->connection->insert('app_config', [
                    'id' => new Ulid()->toBinary(),
                    'company_id' => $company['id'],
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'description' => $description,
                    'field_type' => $fieldType,
                    'form_options' => json_encode($formOptions),
                    'default_value' => $value,
                ]);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        foreach ([self::STRATEGY, self::PREFIX, self::SUFFIX] as $key) {
            $this->connection->delete('app_config', ['setting_key' => $key]);
        }
    }
}
