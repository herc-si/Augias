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
 * Default terms for invoices and quotes, one text for a business client and
 * one for a private individual, given to the companies that already exist —
 * new ones get them from the invoice and quote config providers.
 *
 * The suggested wording is the one of this release, in French for a company
 * whose language is French and in English otherwise: copied here, since a
 * migration must not change with the translations.
 */
final class Version40000_54 extends AbstractMigration
{
    /** @var array<string, array{fr: string, en: string}> */
    private const array TERMS = [
        'invoice/default_terms/business' => [
            'fr' => 'Paiement à 30 jours à compter de la date de facture.
Pas d\'escompte pour paiement anticipé.
En cas de retard de paiement, des pénalités sont exigibles au taux de 3 fois le taux d\'intérêt légal en vigueur, sans qu\'un rappel soit nécessaire.
Indemnité forfaitaire pour frais de recouvrement : 40 € (art. L441-10 et D441-5 du Code de commerce).',
            'en' => 'Payment due within 30 days of the invoice date.
No discount for early payment.
Late payment penalties are due without reminder, at three times the legal interest rate in force.
Fixed compensation for recovery costs: €40 (French Commercial Code, art. L441-10 and D441-5).',
        ],
        'invoice/default_terms/individual' => [
            'fr' => 'Paiement à 30 jours à compter de la date de facture.
En cas de retard de paiement, des intérêts au taux légal pourront être réclamés après mise en demeure.',
            'en' => 'Payment due within 30 days of the invoice date.
Interest at the legal rate may be claimed on late payment after formal notice.',
        ],
        'quote/default_terms/business' => [
            'fr' => 'Devis valable 30 jours à compter de sa date.
Paiement à 30 jours à compter de la date de facture.
Pas d\'escompte pour paiement anticipé.
En cas de retard de paiement, pénalités au taux de 3 fois le taux d\'intérêt légal et indemnité forfaitaire pour frais de recouvrement de 40 € (art. L441-10 du Code de commerce).',
            'en' => 'Quote valid for 30 days from its date.
Payment due within 30 days of the invoice date.
No discount for early payment.
Late payment penalties at three times the legal interest rate, and fixed compensation for recovery costs of €40 (French Commercial Code, art. L441-10).',
        ],
        'quote/default_terms/individual' => [
            'fr' => 'Devis valable 30 jours à compter de sa date.
Paiement à 30 jours à compter de la date de facture.
Si ce devis est accepté à distance ou hors établissement, vous disposez d\'un délai de rétractation de 14 jours à compter de son acceptation (art. L221-18 du Code de la consommation).',
            'en' => 'Quote valid for 30 days from its date.
Payment due within 30 days of the invoice date.
If this quote is accepted at a distance or off-premises, you have 14 days from its acceptance to withdraw (French Consumer Code, art. L221-18).',
        ],
    ];

    public function getDescription(): string
    {
        return 'Default terms for invoices and quotes, per client type';
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
            // The company's language, or the instance's when it has none — the
            // translator's default locale (AUGIAS_LOCALE).
            $locale = (string) $this->connection->fetchOne('SELECT setting_value FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, 'system/company/locale']);

            if ('' === $locale) {
                $locale = (string) ($_SERVER['AUGIAS_LOCALE'] ?? $_ENV['AUGIAS_LOCALE'] ?? getenv('AUGIAS_LOCALE'));
            }
            $language = str_starts_with($locale, 'fr') ? 'fr' : 'en';

            foreach (self::TERMS as $key => $texts) {
                if (false !== $this->connection->fetchOne('SELECT 1 FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, $key])) {
                    continue;
                }

                // Through UlidType: binary on MySQL and SQLite, a uuid on PostgreSQL.
                $this->connection->insert('app_config', [
                    'id' => new Ulid(),
                    'company_id' => $company,
                    'setting_key' => $key,
                    'setting_value' => $texts[$language],
                    'description' => null,
                    'field_type' => TextareaType::class,
                    'form_options' => json_encode(['attr' => ['rows' => 5]]),
                    'default_value' => $texts[$language],
                ], ['id' => UlidType::NAME]);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        foreach (self::TERMS as $key => $texts) {
            $this->connection->delete('app_config', ['setting_key' => $key]);
        }
    }
}
