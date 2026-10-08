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
use Augias\CoreBundle\Form\Type\InvoiceTemplateType;
use Augias\CoreBundle\Templates\BillingTemplateRegistry;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Uid\Ulid;
use function json_encode;

/**
 * Seeds the design settings for companies that already exist.
 *
 * New companies get them from DesignConfigProvider; SettingsRepository::store()
 * only ever updates a row, so a setting with no row could not be saved. The
 * template choice, until now a hosted-only setting, reaches self-hosted
 * companies too, and the rows the hosted service already had are pointed at
 * the picker's new home in CoreBundle.
 */
final class Version40000_38 extends AbstractMigration
{
    private const string OLD_PICKER = 'Augias\\SaasBundle\\Form\\Type\\InvoiceTemplateType';

    public function getDescription(): string
    {
        return 'Seed the design settings: template, accent colour, footer, bank details';
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
        $this->connection->update('app_config', ['field_type' => InvoiceTemplateType::class], ['field_type' => self::OLD_PICKER]);

        $settings = [
            BillingTemplateResolver::TEMPLATE_SETTING_KEY => [BillingTemplateRegistry::DEFAULT_SLUG, 'settings.page.design.template.description', InvoiceTemplateType::class, ['feature_gated' => 'custom_templates']],
            DesignConfigProvider::ACCENT_COLOR => [null, 'settings.page.design.accent_color.description', TextType::class, ['label' => 'settings.page.design.accent_color.label', 'attr' => ['placeholder' => '#1e4976', 'maxlength' => 7]]],
            DesignConfigProvider::FOOTER_TEXT => [null, 'settings.page.design.footer_text.description', TextareaType::class, ['label' => 'settings.page.design.footer_text.label', 'attr' => ['rows' => 3, 'maxlength' => 400]]],
            'design/iban' => [null, 'settings.page.design.iban.description', TextType::class, ['label' => 'settings.page.design.iban.label', 'attr' => ['placeholder' => 'FR76 3000 6000 0112 3456 7890 189', 'maxlength' => 42]]],
            'design/bic' => [null, 'settings.page.design.bic.description', TextType::class, ['label' => 'settings.page.design.bic.label', 'attr' => ['maxlength' => 11]]],
        ];

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM companies') as $company) {
            foreach ($settings as $key => [$default, $description, $type, $options]) {
                $exists = $this->connection->fetchOne('SELECT 1 FROM app_config WHERE company_id = ? AND setting_key = ?', [$company, $key]);

                if (false !== $exists) {
                    continue;
                }

                $this->connection->insert('app_config', [
                    'id' => new Ulid()->toBinary(),
                    'company_id' => $company,
                    'setting_key' => $key,
                    'setting_value' => $default,
                    'description' => $description,
                    'field_type' => $type,
                    'form_options' => json_encode($options),
                    'default_value' => $default,
                ]);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function postDown(Schema $schema): void
    {
        foreach ([DesignConfigProvider::ACCENT_COLOR, DesignConfigProvider::FOOTER_TEXT, 'design/iban', 'design/bic'] as $key) {
            $this->connection->delete('app_config', ['setting_key' => $key]);
        }

        $this->connection->update('app_config', ['field_type' => self::OLD_PICKER], ['field_type' => InvoiceTemplateType::class]);
    }
}
