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
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Uid\Ulid;
use function getenv;
use function json_encode;
use function str_starts_with;

/**
 * The default text of credit notes, given to the companies that already
 * exist: how the amount comes back, one text whoever the client. Same rules
 * as Version40000_54 for the language.
 */
final class Version40000_55 extends AbstractMigration
{
    private const string KEY = 'credit_note/default_terms';

    private const array TEXT = [
        'fr' => 'Montant à déduire de vos prochaines factures.',
        'en' => 'Amount to be deducted from your next invoices.',
    ];

    public function getDescription(): string
    {
        return 'Default text for credit notes';
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
            if (false !== $this->connection->fetchOne('SELECT 1 FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, self::KEY])) {
                continue;
            }

            $locale = (string) $this->connection->fetchOne('SELECT setting_value FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, 'system/company/locale']);

            if ('' === $locale) {
                $locale = (string) ($_SERVER['AUGIAS_LOCALE'] ?? $_ENV['AUGIAS_LOCALE'] ?? getenv('AUGIAS_LOCALE'));
            }

            $text = self::TEXT[str_starts_with($locale, 'fr') ? 'fr' : 'en'];

            // Through UlidType: binary on MySQL and SQLite, a uuid on PostgreSQL.
            $this->connection->insert('app_config', [
                'id' => new Ulid(),
                'company_id' => $company,
                'setting_key' => self::KEY,
                'setting_value' => $text,
                'description' => null,
                'field_type' => TextareaType::class,
                'form_options' => json_encode(['attr' => ['rows' => 3]]),
                'default_value' => $text,
            ], ['id' => UlidType::NAME]);
        }
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        $this->connection->delete('app_config', ['setting_key' => self::KEY]);
    }
}
